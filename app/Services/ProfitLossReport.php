<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\InvoiceCategory;
use App\Models\Transaction;

// The year's profit & loss (Bookkeeping > Profit & loss statement): income
// as it came in -- paid invoices and any other income, less the sales tax
// in it (the state's, not income) -- grouped by what the invoice was for
// (project work, Hosting...), then expenses by expense category, and net
// profit. Cash basis, matching the Bookkeeping cards.
class ProfitLossReport
{
    public static function forYear(int $year): array
    {
        $income = Transaction::where('type', 'income')
            ->whereYear('occurred_on', $year)
            ->with('invoice:id,category_id')
            ->get();

        $categoryNames = InvoiceCategory::pluck('name', 'id');
        $incomeLines = $income
            ->groupBy(fn (Transaction $t) => $t->invoice_id
                ? ($categoryNames[$t->invoice?->category_id] ?? InvoiceCategory::PROJECT_WORK)
                : 'Other income')
            ->map(fn ($rows, $name) => ['name' => $name, 'amount' => round($rows->sum(fn ($t) => (float) $t->amount - (float) $t->tax_amount), 2)])
            ->sortBy(fn ($line) => [$line['name'] === InvoiceCategory::PROJECT_WORK ? 0 : ($line['name'] === 'Other income' ? 2 : 1), $line['name']])
            ->values();

        $expenseLines = Expense::whereYear('date', $year)
            ->with('category:id,name')
            ->get()
            ->groupBy(fn (Expense $e) => $e->category?->name ?? 'Uncategorized')
            ->map(fn ($rows, $name) => ['name' => $name, 'amount' => round((float) $rows->sum('amount'), 2)])
            ->sortByDesc('amount')
            ->values();

        $totalIncome = round($incomeLines->sum('amount'), 2);
        $totalExpenses = round($expenseLines->sum('amount'), 2);

        return [
            'year' => $year,
            'income' => $incomeLines->all(),
            'expenses' => $expenseLines->all(),
            'sales_tax_collected' => round((float) $income->sum('tax_amount'), 2),
            'totals' => [
                'income' => $totalIncome,
                'expenses' => $totalExpenses,
                'net' => round($totalIncome - $totalExpenses, 2),
                'margin_percent' => $totalIncome > 0 ? round(($totalIncome - $totalExpenses) / $totalIncome * 100, 1) : null,
            ],
        ];
    }

    public static function csvRows(array $report): array
    {
        $money = fn ($n) => number_format((float) $n, 2, '.', '');
        $rows = [["Profit & loss {$report['year']}", ''], ['Income', '']];
        foreach ($report['income'] as $line) {
            $rows[] = ['  '.$line['name'], $money($line['amount'])];
        }
        $rows[] = ['Total income', $money($report['totals']['income'])];
        $rows[] = ['Expenses', ''];
        foreach ($report['expenses'] as $line) {
            $rows[] = ['  '.$line['name'], $money($line['amount'])];
        }
        $rows[] = ['Total expenses', $money($report['totals']['expenses'])];
        $rows[] = ['Net profit', $money($report['totals']['net'])];
        $rows[] = ['Sales tax collected (not income)', $money($report['sales_tax_collected'])];

        return $rows;
    }
}
