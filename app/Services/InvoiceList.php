<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// The Invoices page's list, worked out on the server so the browser gets
// only what it shows: filtered by status, category and search, and -- for
// the long views (All, Paid) -- a window of whole months, newest first,
// that "Show earlier months" widens three at a time. The short, actionable
// views (Draft, Outstanding, Overdue) always list everything. The figures
// across the top are over every invoice, whatever's listed.
class InvoiceList
{
    // Months listed at first, and added by each "Show earlier months".
    public const MONTHS_STEP = 3;

    public const STATUSES = ['all', 'draft', 'outstanding', 'overdue', 'paid'];

    // The views that list everything rather than a window of months.
    public const UNWINDOWED = ['draft', 'outstanding', 'overdue'];

    public string $status;

    public string $search;

    // 'all', 'project' (no category: project work), or a category id.
    public string $category;

    public int $months;

    public function __construct(array $filters = [])
    {
        $this->status = in_array($filters['status'] ?? null, self::STATUSES, true) ? $filters['status'] : 'all';
        $this->search = trim((string) ($filters['q'] ?? ''));
        $this->category = (string) ($filters['category'] ?? 'all');
        $months = (int) ($filters['months'] ?? self::MONTHS_STEP);
        // Whole steps, at least one.
        $this->months = max(self::MONTHS_STEP, (int) ceil($months / self::MONTHS_STEP) * self::MONTHS_STEP);
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->only(['status', 'q', 'category', 'months']));
    }

    public function windowed(): bool
    {
        return ! in_array($this->status, self::UNWINDOWED, true);
    }

    // Today, the firm's (Eastern) -- an invoice is overdue the day after
    // it's due there, wherever the server is.
    public static function today(): string
    {
        return Carbon::now('America/New_York')->toDateString();
    }

    // The invoices matching the filters, before any window -- only where
    // clauses, so it can be counted, grouped or loaded.
    public function filtered(): Builder
    {
        $query = Invoice::query();

        match ($this->status) {
            // Drafts include the ones with a send scheduled.
            'draft' => $query->where('status', 'draft'),
            'outstanding' => $query->where('status', 'sent'),
            'overdue' => $query->where('status', 'sent')->whereNotNull('due_on')->where('due_on', '<', self::today()),
            'paid' => $query->where('status', 'paid'),
            default => null,
        };

        if ($this->category === 'project') {
            $query->whereNull('category_id');
        } elseif ($this->category !== 'all' && ctype_digit($this->category)) {
            $query->where('category_id', (int) $this->category);
        }

        // The invoice number, client or project, as the search box says.
        if ($this->search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('invoice_number', 'like', $like)
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like))
                ->orWhereHas('project', fn (Builder $p) => $p->where('name', 'like', $like)));
        }

        return $query;
    }

    // The 'YYYY-MM' months with matching invoices, newest first -- one more
    // than the window holds, to tell whether there are earlier ones.
    protected function recentMonths(): array
    {
        return $this->filtered()
            ->whereNotNull('issued_on')
            ->toBase()
            ->selectRaw('substr(issued_on, 1, 7) as month')
            ->distinct()
            ->orderByDesc('month')
            ->limit($this->months + 1)
            ->pluck('month')
            ->all();
    }

    // What the page lists, and whether earlier months are left to show.
    // Within the window a month is always whole, so its divider's totals
    // add up. Invoices with no issue date are always listed.
    public function page(): array
    {
        $query = $this->filtered()
            ->with(['items', 'company', 'project:id,name', 'payments:id,invoice_id,amount,paid_at'])
            ->withExists(['invoiceSends as has_pending_scheduled_send' => fn ($q) => $q->where('type', 'email')->where('status', 'scheduled')])
            ->orderByDesc('issued_on')
            ->orderByDesc('invoice_number');

        $hasEarlier = false;
        if ($this->windowed()) {
            $months = $this->recentMonths();
            $hasEarlier = count($months) > $this->months;
            $window = array_slice($months, 0, $this->months);

            $query->where(fn (Builder $q) => $q
                ->whereNull('issued_on')
                ->when($window, fn (Builder $q) => $q->orWhere('issued_on', '>=', end($window).'-01')));
        }

        return ['invoices' => $query->get(), 'hasEarlier' => $hasEarlier];
    }

    // The figures across the top, over every invoice: what's owed (sent
    // and unpaid, overdue included), what's overdue, and this month's
    // payments and sends -- each a count and an amount. Months are the
    // firm's (Eastern), as the page always showed them.
    public static function metrics(): array
    {
        $now = Carbon::now('America/New_York');
        $today = $now->toDateString();
        $figure = fn ($list, callable $amount) => ['count' => $list->count(), 'amount' => round($list->sum($amount), 2)];
        $owed = fn (Invoice $invoice) => $invoice->total();

        $unpaid = Invoice::where('status', 'sent')->with('items')->get();
        $overdue = $unpaid->filter(fn (Invoice $i) => $i->due_on && $i->due_on->toDateString() < $today);

        // Payments are stored in the app's time (Eastern); sends in UTC.
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();
        $payments = Payment::whereNotNull('invoice_id')->whereBetween('paid_at', [$start, $end])->get();
        $sent = Invoice::with('items')
            ->whereBetween('sent_at', [$start->copy()->utc()->format('Y-m-d H:i:s'), $end->copy()->utc()->format('Y-m-d H:i:s')])
            ->get();

        return [
            'outstanding' => $figure($unpaid, $owed),
            'overdue' => $figure($overdue, $owed),
            'paid' => $figure($payments, fn (Payment $p) => (float) $p->amount),
            'sent' => $figure($sent, $owed),
        ];
    }

    // The filters as the page's address carries them -- defaults left out.
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
