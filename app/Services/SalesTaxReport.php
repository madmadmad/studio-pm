<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

// The monthly sales tax report (Bookkeeping > Sales tax): for each month
// of a year, the figures a sales tax return asks for -- gross sales,
// taxable and exempt sales, and the tax collected -- plus the taxed
// payments behind them. Months go by when the money came in (each income
// entry's date), and gross sales leave out the tax itself.
class SalesTaxReport
{
    public static function forYear(int $year): array
    {
        $income = Transaction::where('type', 'income')
            ->whereYear('occurred_on', $year)
            ->with('invoice:id,invoice_number,company_id', 'invoice.company:id,name')
            ->orderBy('occurred_on')
            ->get()
            ->groupBy(fn (Transaction $t) => $t->occurred_on->format('Y-m'));

        // Every month of a past year; this year's up to now.
        $lastMonth = $year < now()->year ? 12 : ($year === now()->year ? now()->month : 0);

        $months = [];
        for ($m = 1; $m <= $lastMonth; $m++) {
            $key = sprintf('%d-%02d', $year, $m);
            $months[] = static::month($key, $income->get($key, collect()));
        }

        return [
            'year' => $year,
            'months' => $months,
            'totals' => [
                'gross_sales' => round(array_sum(array_column($months, 'gross_sales')), 2),
                'taxable_sales' => round(array_sum(array_column($months, 'taxable_sales')), 2),
                'exempt_sales' => round(array_sum(array_column($months, 'exempt_sales')), 2),
                'tax' => round(array_sum(array_column($months, 'tax')), 2),
            ],
        ];
    }

    private static function month(string $key, $entries): array
    {
        $gross = round($entries->sum(fn ($t) => (float) $t->amount - (float) $t->tax_amount), 2);
        $taxable = round($entries->sum(fn ($t) => (float) $t->taxable_amount), 2);

        return [
            'month' => $key,
            'label' => Carbon::createFromFormat('Y-m-d', "{$key}-01")->format('F Y'),
            'gross_sales' => $gross,
            'taxable_sales' => $taxable,
            'exempt_sales' => round(max($gross - $taxable, 0), 2),
            'tax' => round($entries->sum(fn ($t) => (float) $t->tax_amount), 2),
            'payments' => $entries->filter(fn ($t) => (float) $t->tax_amount > 0)->map(fn ($t) => [
                'id' => $t->id,
                'date' => $t->occurred_on->toDateString(),
                'invoice_id' => $t->invoice_id,
                'invoice_number' => $t->invoice?->invoice_number,
                'client' => $t->invoice?->company?->name,
                'description' => $t->description,
                'taxable_sales' => (float) $t->taxable_amount,
                'tax' => (float) $t->tax_amount,
            ])->values()->all(),
        ];
    }

    // The report as CSV rows: a line per month, then the year's total.
    public static function csvRows(array $report): array
    {
        $rows = [['Month', 'Gross sales', 'Taxable sales', 'Exempt sales', 'Sales tax collected']];
        foreach ($report['months'] as $month) {
            $rows[] = [$month['label'], ...static::money($month)];
        }
        $rows[] = ["Total {$report['year']}", ...static::money($report['totals'])];

        return $rows;
    }

    private static function money(array $figures): array
    {
        return array_map(
            fn ($key) => number_format($figures[$key], 2, '.', ''),
            ['gross_sales', 'taxable_sales', 'exempt_sales', 'tax'],
        );
    }
}
