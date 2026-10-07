<?php

namespace App\Services\LedgerReports;

use App\Models\Account;
use App\Support\Money;
use Illuminate\Support\Carbon;

// Bookkeeping > Trial balance: every account's balance on a date, in a
// debit or a credit column, and the two columns' totals -- equal, or the
// books are wrong. Balance-sheet accounts carry everything to date;
// income and expense accounts this year's only, with earlier years'
// profit folded into Retained Earnings.
class TrialBalance
{
    public static function asOf(Carbon $asOf): array
    {
        $accounts = Balances::accounts();
        $toDate = Balances::sums(null, $asOf);
        $thisYear = Balances::sums(Balances::fiscalYearStart($asOf), $asOf);
        $priorProfit = Balances::priorYearsProfit($accounts, $asOf);

        $rows = [];
        foreach ($accounts as $account) {
            /** @var Account $account */
            $sums = (Balances::isIncomeStatement($account) ? $thisYear : $toDate)[$account->id] ?? ['debit' => 0, 'credit' => 0];
            $net = $sums['debit'] - $sums['credit'];
            if ($account->system_key === 'retained_earnings') {
                $net -= $priorProfit;
            }
            if ($net === 0) {
                continue;
            }
            $rows[] = [
                'account' => $account->only('id', 'code', 'name', 'type'),
                'debit' => max($net, 0),
                'credit' => max(-$net, 0),
            ];
        }

        $debits = array_sum(array_column($rows, 'debit'));
        $credits = array_sum(array_column($rows, 'credit'));

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'totals' => ['debit' => $debits, 'credit' => $credits],
            'balanced' => $debits === $credits,
            'prior_years_profit' => $priorProfit,
        ];
    }

    public static function csvRows(array $report): array
    {
        $rows = [['Code', 'Account', 'Debit', 'Credit']];
        foreach ($report['rows'] as $row) {
            $rows[] = [$row['account']['code'], $row['account']['name'], $row['debit'] ? Money::fromCents($row['debit']) : '', $row['credit'] ? Money::fromCents($row['credit']) : ''];
        }
        $rows[] = ['', 'Total', Money::fromCents($report['totals']['debit']), Money::fromCents($report['totals']['credit'])];

        return $rows;
    }
}
