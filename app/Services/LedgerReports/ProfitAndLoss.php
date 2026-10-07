<?php

namespace App\Services\LedgerReports;

use App\Models\Account;
use App\Support\Money;
use Illuminate\Support\Carbon;

// Bookkeeping > Profit & loss (from the ledger), between two dates: income
// by account, less cost of revenue for gross profit (what hosting,
// printing and client media earn over what they cost), less operating
// expenses for net profit. Cash basis, as the ledger is.
class ProfitAndLoss
{
    public static function for(Carbon $from, Carbon $to): array
    {
        $accounts = Balances::accounts();
        $sums = Balances::sums($from, $to);

        $section = fn (callable $belongs) => $accounts->filter($belongs)
            ->map(fn (Account $a) => ['account' => $a->only('id', 'code', 'name'), 'amount' => Balances::normal($a, $sums[$a->id] ?? null)])
            ->filter(fn (array $line) => $line['amount'] !== 0)
            ->values()
            ->all();

        $income = $section(fn (Account $a) => $a->type === Account::INCOME);
        $costOfRevenue = $section(fn (Account $a) => $a->type === Account::EXPENSE && $a->parent?->system_key === 'cost_of_revenue');
        $expenses = $section(fn (Account $a) => $a->type === Account::EXPENSE && $a->parent?->system_key !== 'cost_of_revenue');

        $totalIncome = array_sum(array_column($income, 'amount'));
        $totalCost = array_sum(array_column($costOfRevenue, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $gross = $totalIncome - $totalCost;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'income' => $income,
            'cost_of_revenue' => $costOfRevenue,
            'expenses' => $expenses,
            'totals' => [
                'income' => $totalIncome,
                'cost_of_revenue' => $totalCost,
                'gross_profit' => $gross,
                'expenses' => $totalExpenses,
                'net' => $gross - $totalExpenses,
            ],
        ];
    }

    public static function csvRows(array $report): array
    {
        $rows = [['Section', 'Account', 'Amount']];
        foreach (['income' => 'Income', 'cost_of_revenue' => 'Cost of revenue', 'expenses' => 'Operating expenses'] as $key => $label) {
            foreach ($report[$key] as $line) {
                $rows[] = [$label, "{$line['account']['code']} {$line['account']['name']}", Money::fromCents($line['amount'])];
            }
            $rows[] = [$label, "Total {$label}", Money::fromCents($report['totals'][$key])];
            if ($key === 'cost_of_revenue') {
                $rows[] = ['', 'Gross profit', Money::fromCents($report['totals']['gross_profit'])];
            }
        }
        $rows[] = ['', 'Net profit', Money::fromCents($report['totals']['net'])];

        return $rows;
    }
}
