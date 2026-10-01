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
