<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = ['type', 'amount', 'tax_amount', 'taxable_amount', 'category', 'occurred_on', 'description', 'invoice_id', 'project_id'];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
    ];

    // The Bookkeeping screen's month at a glance -- a simple income vs.
    // expense summary, not double-entry accounting. Sales tax received is
    // the state's, not income, so it's taken out of income and net and
    // reported on its own (for filing), with the year to date beside it.
    // The year month by month, for Bookkeeping's chart: income (less the
    // sales tax in it, as in monthlySummary), expenses, and net, Jan..Dec.
    // Months still to come are null, so the chart leaves them empty.
    public static function yearSeries(int $year): array
    {
        $income = static::where('type', 'income')->whereYear('occurred_on', $year)
            ->get(['amount', 'tax_amount', 'occurred_on'])
            ->groupBy(fn (Transaction $t) => $t->occurred_on->month)
            ->map(fn ($month) => (float) $month->sum('amount') - (float) $month->sum('tax_amount'));

        $expenses = Expense::whereYear('date', $year)->get(['amount', 'date'])
            ->groupBy(fn (Expense $e) => $e->date->month)
            ->map(fn ($month) => (float) $month->sum('amount'));

        $lastMonth = $year < now()->year ? 12 : ($year === now()->year ? now()->month : 0);

        return collect(range(1, 12))->map(function ($m) use ($income, $expenses, $lastMonth) {
            if ($m > $lastMonth) {
                return ['month' => $m, 'income' => null, 'expenses' => null, 'net' => null];
            }
            $in = round($income->get($m, 0), 2);
            $out = round($expenses->get($m, 0), 2);

            return ['month' => $m, 'income' => $in, 'expenses' => $out, 'net' => round($in - $out, 2)];
        })->all();
    }

    public static function monthlySummary(string $month): array
    {
        [$year, $monthNumber] = explode('-', $month);

        $incomeThisMonth = static::where('type', 'income')
            ->whereYear('occurred_on', $year)
            ->whereMonth('occurred_on', $monthNumber);

        $received = (float) (clone $incomeThisMonth)->sum('amount');
        $salesTax = (float) (clone $incomeThisMonth)->sum('tax_amount');
        $income = round($received - $salesTax, 2);

        $expenses = (float) Expense::whereYear('date', $year)
            ->whereMonth('date', $monthNumber)
            ->sum('amount');

        $salesTaxYear = (float) static::where('type', 'income')
            ->whereYear('occurred_on', $year)
            ->whereDate('occurred_on', '<=', now()->setDate((int) $year, (int) $monthNumber, 1)->endOfMonth())
            ->sum('tax_amount');

        return [
            'month' => $month,
            'income' => $income,
            'expenses' => $expenses,
            'net' => round($income - $expenses, 2),
            'sales_tax' => $salesTax,
            'sales_tax_year' => $salesTaxYear,
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
