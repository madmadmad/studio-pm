<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\Expense;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

// An expense: debit its category's account, credit the account it was
// paid from (the Capital One card unless it says otherwise).
//
// The category decides the account -- its billable account instead when
// the expense is billed to a client (Advertising billed to a client is
// Client Media Spend, not our marketing). No category, or one with no
// account, posts to Uncategorized Expense. A cost split across clients
// is one debit per client; otherwise the debit carries the project's
// client. What a rebilled expense earns posts with the client's payment.
class ExpensePoster extends Poster
{
    protected function draft(Model $record): ?array
    {
        /** @var Expense $record */
        $cents = Money::toCents($record->amount);
        if ($cents === 0) {
            return null;
        }

        $category = $record->category;
        $mappedAccount = $record->is_billable && $category?->billableAccount ? $category->billableAccount : $category?->account;
        $expenseAccount = $this->mapped($mappedAccount, 'uncategorized_expense');
        $paidFrom = $this->mapped($record->paidFrom, 'capital_one_card');

        $lines = [
            ...$this->debits($record, $expenseAccount, abs($cents)),
            ['account' => $paidFrom, 'credit_cents' => abs($cents)],
        ];

        // A refund (a negative amount) runs the other way.
        if ($cents < 0) {
            $lines = array_map(fn (array $line) => [
                'debit_cents' => $line['credit_cents'] ?? 0,
                'credit_cents' => $line['debit_cents'] ?? 0,
            ] + $line, $lines);
        }

        return ['date' => $record->date, 'memo' => $record->name, 'lines' => $lines];
    }

    // One debit per client in a split -- the shares are validated to add
    // up to the expense, and any cent of rounding goes on the largest --
    // or a single debit for the project's client, if any.
    private function debits(Expense $expense, Account $account, int $cents): array
    {
        $splits = $expense->splits()->get();
        if ($splits->isEmpty() || $expense->amount < 0) {
            return [['account' => $account, 'debit_cents' => $cents, 'company_id' => $expense->project?->company_id]];
        }

        $lines = $splits->map(fn ($split) => [
            'account' => $account,
            'debit_cents' => Money::toCents($split->amount),
            'company_id' => $split->company_id,
        ])->sortByDesc('debit_cents')->values()->all();
        $lines[0]['debit_cents'] += $cents - array_sum(array_column($lines, 'debit_cents'));

        return array_values(array_filter($lines, fn (array $line) => $line['debit_cents'] > 0));
    }
}
