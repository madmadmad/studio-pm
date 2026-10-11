<?php

namespace App\Services;

use App\Models\Expense;
use App\Support\MonthWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// The Expenses page's list, worked out on the server as the Invoices
// page's is (InvoiceList): filtered by billing status, category and
// search, and for the long views (All, Unbilled, Paid) a window of whole
// months that "Show earlier months" widens three at a time. Billed --
// invoiced, waiting on the client -- lists everything. The figures and the
// year's chart above are over every expense, whatever's listed.
class ExpenseList
{
    public const STATUSES = ['all', 'unbilled', 'billed', 'billed_and_paid'];

    // The views that list everything rather than a window of months.
    public const UNWINDOWED = ['billed'];

    public string $status;

    public string $search;

    // 'all', 'uncategorized', or a category id.
    public string $category;

    public int $months;

    public function __construct(array $filters = [])
    {
        $this->status = in_array($filters['status'] ?? null, self::STATUSES, true) ? $filters['status'] : 'all';
        $this->search = trim((string) ($filters['q'] ?? ''));
        $this->category = (string) ($filters['category'] ?? 'all');
        $this->months = MonthWindow::months($filters['months'] ?? MonthWindow::STEP);
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->only(['status', 'q', 'category', 'months']));
    }

    public function windowed(): bool
    {
        return ! in_array($this->status, self::UNWINDOWED, true);
    }

    // The expenses matching the filters, before any window.
    public function filtered(): Builder
    {
        $query = Expense::query();

        if ($this->status !== 'all') {
            $query->where('billing_status', $this->status);
        }

        if ($this->category === 'uncategorized') {
            $query->whereNull('category_id');
        } elseif ($this->category !== 'all' && ctype_digit($this->category)) {
            $query->where('category_id', (int) $this->category);
        }

        if ($this->search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
            $query->where('name', 'like', $like);
        }

        return $query;
    }

    // What the page lists, newest first, and whether earlier months are
    // left to show.
    public function page(): array
    {
        $query = $this->filtered()
            ->with(['category', 'project', 'tax', 'splits.company:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        $hasEarlier = $this->windowed()
            && MonthWindow::apply($query, $this->filtered(), 'date', $this->months);

        return ['expenses' => $query->get(), 'hasEarlier' => $hasEarlier];
    }

    // The figures across the top, over every expense: spent this month and
    // this year (calendar, by the expense's date), billable costs not yet
    // on an invoice, and ones invoiced but not yet paid.
    public static function metrics(): array
    {
        $today = Carbon::now('America/New_York');
        $figure = fn (Builder $q) => ['count' => (clone $q)->count(), 'amount' => round((float) (clone $q)->sum('amount'), 2)];

        return [
            'month' => $figure(Expense::whereBetween('date', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])),
            'year' => $figure(Expense::whereBetween('date', [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfYear()->toDateString()])),
            'ready' => $figure(Expense::where('is_billable', true)->where('billing_status', 'unbilled')),
            'billed' => $figure(Expense::where('billing_status', 'billed')),
        ];
    }

    // The year's spending by month for the chart, split by who carries it:
    // billed to clients (a billable expense, or the clients' shares of a
    // split one) and the studio's own cost (the rest, a Not billed share
    // included). Months still to come are null.
    public static function yearSpend(): array
    {
        $today = Carbon::now('America/New_York');
        $currentMonth = $today->month;
        $months = collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => $m > $currentMonth
            ? ['month' => $m, 'billable' => null, 'studio' => null, 'total' => null]
            : ['month' => $m, 'billable' => 0.0, 'studio' => 0.0, 'total' => 0.0]])->all();

        $expenses = Expense::with('splits:id,expense_id,company_id,amount')
            ->whereBetween('date', [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfMonth()->toDateString()])
            ->get(['id', 'date', 'amount', 'is_billable']);

        foreach ($expenses as $expense) {
            $m = $expense->date->month;
            $amount = (float) $expense->amount;
            $billable = $expense->is_billable
                ? $amount
                : min($amount, (float) $expense->splits->whereNotNull('company_id')->sum('amount'));
            $months[$m]['billable'] += $billable;
            $months[$m]['studio'] += $amount - $billable;
            $months[$m]['total'] += $amount;
        }

        $round = fn ($n) => $n === null ? null : round($n, 2);

        return [
            'year' => (string) $today->year,
            'months' => array_values(array_map(fn (array $m) => [
                'month' => $m['month'],
                'billable' => $round($m['billable']),
                'studio' => $round($m['studio']),
                'total' => $round($m['total']),
            ], $months)),
        ];
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'q' => $this->search,
            'category' => $this->category,
            'months' => $this->months,
        ];
    }
}
