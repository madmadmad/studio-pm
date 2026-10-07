<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Entries made by hand (Bookkeeping > Journal): a general journal entry,
// and the everyday ones that have their own simpler forms -- a transfer
// (the monthly card payment), a Stripe payout, a payroll run. Each goes
// through the Ledger, which refuses an unbalanced entry or a locked date
// (422). Amounts arrive as decimals and post as cents.
class JournalEntryController extends Controller
{
    public function __construct(private Ledger $ledger) {}

    // Any balanced set of lines.
    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $line) => [
            'account' => (int) $line['account_id'],
            'debit_cents' => Money::toCents($line['debit'] ?? null),
            'credit_cents' => Money::toCents($line['credit'] ?? null),
            'company_id' => $line['company_id'] ?? null,
            'description' => $line['description'] ?? null,
        ], $data['lines']);

        return $this->posted($this->ledger->post($data['entry_date'], $lines, $data['memo']));
    }

    // Money moved between the studio's own accounts: paying the card from
    // checking, an owner putting money in or taking a distribution, sending
    // the state its sales tax. Never an expense.
    public function transfer(Request $request)
    {
        $balanceSheet = Rule::exists('accounts', 'id')->whereIn('type', [Account::ASSET, Account::LIABILITY, Account::EQUITY]);
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'from_account_id' => ['required', 'integer', $balanceSheet],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', $balanceSheet],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'memo' => ['nullable', 'string', 'max:255'],
        ], ['to_account_id.different' => 'Pick two different accounts.']);

        $from = Account::findOrFail($data['from_account_id']);
        $to = Account::findOrFail($data['to_account_id']);
        $cents = Money::toCents($data['amount']);

        return $this->posted($this->ledger->post($data['entry_date'], [
            ['account' => $to, 'debit_cents' => $cents],
            ['account' => $from, 'credit_cents' => $cents],
        ], ($data['memo'] ?? null) ?: "Transfer from {$from->name} to {$to->name}"));
    }

    // Stripe paying out to the bank: the deposit moves out of Stripe
    // Clearing into checking. Fees are normally booked with each payment
    // already; any that weren't (the lookup failed) are entered here and
    // come out of clearing too.
    public function payout(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'deposited' => ['required', 'numeric', 'min:0.01'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $deposited = Money::toCents($data['deposited']);
        $fees = Money::toCents($data['fees'] ?? null);

        return $this->posted($this->ledger->post($data['entry_date'], array_values(array_filter([
            ['account' => 'checking', 'debit_cents' => $deposited],
            $fees > 0 ? ['account' => 'merchant_fees', 'debit_cents' => $fees] : null,
            ['account' => 'stripe_clearing', 'credit_cents' => $deposited + $fees],
        ])), ($data['memo'] ?? null) ?: 'Stripe payout'));
    }

    // One pay period, entered from the payroll provider's report: each
    // cost to its account, and the total out of checking (the provider
    // draws it all from there, so there are no withholding payables).
    public function payroll(Request $request)
    {
        $parts = ['officer_compensation', 'wages', 'payroll_taxes', 'retirement_expense'];
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys($parts, ['nullable', 'numeric', 'min:0']),
        ]);

        $lines = collect($parts)
            ->map(fn (string $key) => ['account' => $key, 'debit_cents' => Money::toCents($data[$key] ?? null)])
            ->filter(fn (array $line) => $line['debit_cents'] > 0)
            ->values()
            ->all();
        abort_if($lines === [], 422, 'Enter at least one payroll amount.');
        $lines[] = ['account' => 'checking', 'credit_cents' => array_sum(array_column($lines, 'debit_cents'))];

        return $this->posted($this->ledger->post($data['entry_date'], $lines, ($data['memo'] ?? null) ?: 'Payroll'));
    }

    // Undo an entry made by hand. One the app posted for an expense or a
    // payment follows that record -- reversing it here would just be put
    // back -- so that's changed or deleted instead. Dated like the
    // original unless that's locked and another date is given.
    public function reverse(Request $request, JournalEntry $journalEntry)
    {
        $data = $request->validate(['entry_date' => ['nullable', 'date']]);
        abort_unless($journalEntry->isManual(), 422, 'This entry follows its expense or payment. Change or delete that instead.');

        $this->ledger->reverse($journalEntry, $data['entry_date'] ?? null);

        return $this->posted($journalEntry->fresh());
    }

    private function posted(JournalEntry $entry): array
    {
        return $entry->load(['lines.account', 'lines.company', 'source', 'creator', 'reverses', 'reversal'])->summary();
    }
}
