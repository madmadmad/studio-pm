<?php

namespace App\Services;

use App\Exceptions\LedgerException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PlaidDismissal;
use App\Models\PlaidItem;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Brings a connected bank's charges into the expense list. Each posted
// charge becomes an unbilled, non-billable expense ("Chase ••4521" as its
// source). A refund or credit to a credit card (a vendor giving back sales
// tax, a return) comes in too, as a negative expense in the category of
// that vendor's last charge, so it comes back off the same cost. Other
// money coming in (deposits) and transfers/card payments are left out, as
// are pending charges until they post. Plaid's changes
// and removals are applied to expenses still unbilled; a charge deleted
// from the list is remembered and never imported again.
class PlaidSync
{
    // Plaid categories that aren't spending.
    private const SKIPPED_CATEGORIES = ['INCOME', 'TRANSFER_IN', 'TRANSFER_OUT', 'LOAN_PAYMENTS'];

    // A first guess at the expense category, from Plaid's -- matched to
    // ours by name, so it's only set where that category exists.
    private const CATEGORY_GUESSES = [
        'GENERAL_MERCHANDISE_OFFICE_SUPPLIES' => 'Other Office Expenses',
        'GENERAL_MERCHANDISE_ELECTRONICS' => 'Electronics & Furniture',
        'TRAVEL_FLIGHTS' => 'Flights, Taxi & Transportation',
        'TRAVEL_TAXIS_AND_RIDE_SHARES' => 'Flights, Taxi & Transportation',
        'TRAVEL_LODGING' => 'Hotel & Accommodation',
        'TRAVEL' => 'Other Travel Expenses',
        'TRANSPORTATION_GAS' => 'Gas & Fuel',
        'TRANSPORTATION' => 'Flights, Taxi & Transportation',
    ];

    public function __construct(private PlaidClient $plaid) {}

    /**
     * @return array{added: int, updated: int, removed: int}
     */
    public function sync(PlaidItem $item): array
    {
        try {
            [$added, $modified, $removed, $accounts, $cursor] = $this->fetch($item);
        } catch (RequestException $e) {
            $item->update(['last_error' => PlaidClient::errorCode($e) ?? 'SYNC_FAILED']);
            throw $e;
        }

        $counts = DB::transaction(function () use ($item, $added, $modified, $removed, $accounts, $cursor) {
            $item->update([
                'accounts' => collect($accounts)->mapWithKeys(fn ($a) => [$a['account_id'] => ['name' => $a['name'], 'mask' => $a['mask'] ?? null, 'type' => $a['type'] ?? null]])->all() ?: $item->accounts,
                'cursor' => $cursor,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            $dismissed = PlaidDismissal::whereIn('transaction_id', collect($added)->pluck('transaction_id'))->pluck('transaction_id')->flip();
            $counts = ['added' => 0, 'updated' => 0, 'removed' => 0];

            foreach ($added as $t) {
                if ($dismissed->has($t['transaction_id']) || ! $this->isExpense($t, $item)) {
                    continue;
                }
                $expense = Expense::firstOrNew(['plaid_transaction_id' => $t['transaction_id']]);
                if ($expense->exists) {
                    continue;
                }
                $expense->fill([
                    'name' => $t['merchant_name'] ?: $t['name'],
                    'amount' => $t['amount'],
                    'currency' => $t['iso_currency_code'] ?? 'USD',
                    'date' => $t['date'],
                    'category_id' => $t['amount'] < 0 ? $this->refundCategory($t) : $this->guessCategory($t),
                    'is_billable' => false,
                    'billing_status' => 'unbilled',
                    'source_label' => $item->sourceLabel($t['account_id'] ?? null),
                ])->save();
                $counts['added']++;
            }

            foreach ($modified as $t) {
                $expense = Expense::where('plaid_transaction_id', $t['transaction_id'])->where('billing_status', 'unbilled')->first();
                if (! $expense) {
                    continue;
                }
                if (! $this->isExpense($t, $item)) {
                    $counts['removed'] += (int) $this->unlessLocked($expense, fn () => $expense->delete());

                    continue;
                }
                $counts['updated'] += (int) $this->unlessLocked($expense, fn () => $expense->update(['amount' => $t['amount'], 'date' => $t['date']]));
            }

            // One by one, not a bulk delete, so each is reversed out of the
            // ledger.
            Expense::whereIn('plaid_transaction_id', collect($removed)->pluck('transaction_id'))
                ->where('billing_status', 'unbilled')
                ->get()
                ->each(function (Expense $expense) use (&$counts) {
                    $counts['removed'] += (int) $this->unlessLocked($expense, fn () => $expense->delete());
                });

            return $counts;
        });

        return $counts;
    }

    // Every page since the saved cursor. If the bank's data changes mid-way
    // Plaid asks for the whole run again from the start.
    private function fetch(PlaidItem $item): array
    {
        for ($attempt = 0; ; $attempt++) {
            $added = $modified = $removed = $accounts = [];
            $cursor = $item->cursor;
            try {
                do {
                    $page = $this->plaid->syncTransactions($item->access_token, $cursor);
                    array_push($added, ...$page['added']);
                    array_push($modified, ...$page['modified']);
                    array_push($removed, ...$page['removed']);
                    $accounts = $page['accounts'] ?? $accounts;
                    $cursor = $page['next_cursor'];
                } while ($page['has_more']);

                return [$added, $modified, $removed, $accounts, $cursor];
            } catch (RequestException $e) {
                if ($attempt < 2 && PlaidClient::errorCode($e) === 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION') {
                    continue;
                }
                throw $e;
            }
        }
    }

    // Posted, and spending: money out (Plaid's amounts are positive for
    // it), or a refund or credit to a credit card.
    private function isExpense(array $t, PlaidItem $item): bool
    {
        if (! empty($t['pending']) || in_array($t['personal_finance_category']['primary'] ?? null, self::SKIPPED_CATEGORIES, true)) {
            return false;
        }

        return (float) $t['amount'] > 0
            || ((float) $t['amount'] < 0 && ($item->accounts[$t['account_id'] ?? '']['type'] ?? null) === 'credit');
    }

    // A refund goes where that vendor's charges go: the category of their
    // latest charge, if it has one.
    private function refundCategory(array $t): ?int
    {
        return Expense::where('name', $t['merchant_name'] ?: $t['name'])->where('amount', '>', 0)->whereNotNull('category_id')
            ->latest('date')->value('category_id');
    }

    private function guessCategory(array $t): ?int
    {
        $plaid = $t['personal_finance_category'] ?? [];
        $name = self::CATEGORY_GUESSES[$plaid['detailed'] ?? ''] ?? self::CATEGORY_GUESSES[$plaid['primary'] ?? ''] ?? null;

        return $name ? ExpenseCategory::whereRaw('lower(name) = ?', [strtolower($name)])->value('id') : null;
    }

    // Applies a bank's change to an expense, unless the books are locked
    // for its date -- then the expense stays as it was, and it's logged
    // for a person to sort out. True when the change was made.
    private function unlessLocked(Expense $expense, callable $change): bool
    {
        try {
            $change();

            return true;
        } catch (LedgerException $e) {
            Log::warning("Plaid change to expense #{$expense->id} skipped: {$e->getMessage()}");

            return false;
        }
    }
}
