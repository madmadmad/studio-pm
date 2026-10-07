<?php

namespace App\Services\LedgerReports;

use App\Models\Account;
use App\Support\Money;
use Illuminate\Support\Carbon;

// Bookkeeping > Balance sheet on a date: what the studio has (checking,
// Stripe Clearing), what it owes (the card, sales tax), and equity --
// shareholder money in and out, earlier years' profit, and this year's so
// far. Assets equal liabilities plus equity, or the books are wrong.
class BalanceSheet
{
    public static function asOf(Carbon $asOf): array
    {
        $accounts = Balances::accounts();
        $toDate = Balances::sums(null, $asOf);
        $priorProfit = Balances::priorYearsProfit($accounts, $asOf);
        $yearProfit = Balances::profit($accounts, Balances::sums(Balances::fiscalYearStart($asOf), $asOf));

        $section = fn (string $type) => $accounts->where('type', $type)
            ->map(fn (Account $a) => [
                'account' => $a->only('id', 'code', 'name'),
                'amount' => Balances::normal($a, $toDate[$a->id] ?? null) + ($a->system_key === 'retained_earnings' ? $priorProfit : 0),
            ])
            ->filter(fn (array $line) => $line['amount'] !== 0)
            ->values()
            ->all();

        $assets = $section(Account::ASSET);
        $liabilities = $section(Account::LIABILITY);
        $equity = $section(Account::EQUITY);
        if ($yearProfit !== 0) {
            $equity[] = ['account' => ['id' => null, 'code' => '', 'name' => 'Current year earnings'], 'amount' => $yearProfit];
        }

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount'));

        return [
            'as_of' => $asOf->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'liabilities_and_equity' => $totalLiabilities + $totalEquity,
            ],
            'balanced' => $totalAssets === $totalLiabilities + $totalEquity,
        ];
    }

    public static function csvRows(array $report): array
    {
        $rows = [['Section', 'Account', 'Amount']];
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $label) {
            foreach ($report[$key] as $line) {
                $rows[] = [$label, trim("{$line['account']['code']} {$line['account']['name']}"), Money::fromCents($line['amount'])];
            }
            $rows[] = [$label, "Total {$label}", Money::fromCents($report['totals'][$key])];
        }
        $rows[] = ['', 'Total liabilities and equity', Money::fromCents($report['totals']['liabilities_and_equity'])];

        return $rows;
    }
}
