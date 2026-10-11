<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

// A long list a few months at a time (Invoices, Expenses): the latest
// months that have rows, newest first, each one whole -- so a month's
// total always adds up -- widened a step at a time by "Show earlier
// months". Months with nothing in them are skipped rather than shown
// empty. Rows with no date are always included.
class MonthWindow
{
    public const STEP = 3;

    // A requested number of months as whole steps, at least one.
    public static function months(mixed $requested): int
    {
        return max(self::STEP, (int) ceil(((int) $requested) / self::STEP) * self::STEP);
    }

    // Limits $query (rows to list) to the latest $months months with rows
    // in $filtered (the same rows, with only where clauses), by the date
    // in $column. Returns whether earlier months are left.
    public static function apply(Builder $query, Builder $filtered, string $column, int $months): bool
    {
        $recent = $filtered
            ->whereNotNull($column)
            ->toBase()
            ->selectRaw("substr({$column}, 1, 7) as month")
            ->distinct()
            ->orderByDesc('month')
            ->limit($months + 1)
            ->pluck('month')
            ->all();

        $window = array_slice($recent, 0, $months);

        $query->where(fn (Builder $q) => $q
            ->whereNull($column)
            ->when($window, fn (Builder $q) => $q->orWhere($column, '>=', end($window).'-01')));

        return count($recent) > $months;
    }
}
