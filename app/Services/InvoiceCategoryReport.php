<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceCategory;

// Invoices by category for a year (Bookkeeping > Invoices by category):
// every invoice issued that year -- sent or paid, not drafts -- grouped by
// what it was for (project work, Hosting...), each group's count, total
// billed, paid and still outstanding, and the invoices themselves.
class InvoiceCategoryReport
{
    public static function forYear(int $year): array
    {
        $invoices = Invoice::whereIn('status', ['sent', 'paid'])
            ->whereYear('issued_on', $year)
            ->with(['items', 'payments', 'company:id,name', 'project:id,name'])
            ->orderBy('issued_on')
            ->orderBy('invoice_number')
            ->get();

        // Project work first, then every category (even with nothing in it).
        $groups = collect([['id' => null, 'name' => InvoiceCategory::PROJECT_WORK]])
            ->concat(InvoiceCategory::orderBy('name')->get(['id', 'name'])->map->only('id', 'name'));

        $categories = $groups->map(function ($group) use ($invoices) {
            $mine = $invoices->filter(fn (Invoice $i) => $i->category_id === $group['id'])->values();
            $outstanding = $mine->where('status', 'sent')->sum(fn (Invoice $i) => $i->remainingBalance());
            $total = $mine->sum(fn (Invoice $i) => $i->total());

            return [
                'id' => $group['id'],
                'name' => $group['name'],
                'count' => $mine->count(),
                'total' => round($total, 2),
                'outstanding' => round($outstanding, 2),
                'paid' => round($total - $outstanding, 2),
                'invoices' => $mine->map(fn (Invoice $i) => [
                    'id' => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'client' => $i->company?->name,
                    'project' => $i->project?->name,
                    'issued_on' => $i->issued_on?->toDateString(),
                    'due_on' => $i->due_on?->toDateString(),
                    'status' => $i->status,
                    'total' => $i->total(),
                    'balance' => $i->status === 'sent' ? $i->remainingBalance() : 0,
                ])->all(),
            ];
        })->values();

        return [
            'year' => $year,
            'categories' => $categories->all(),
            'totals' => [
                'count' => $invoices->count(),
                'total' => round($categories->sum('total'), 2),
                'paid' => round($categories->sum('paid'), 2),
                'outstanding' => round($categories->sum('outstanding'), 2),
            ],
        ];
    }

    // Every invoice in the report as a CSV row, under its category.
    public static function csvRows(array $report): array
    {
        $rows = [['Category', 'Invoice', 'Client', 'Project', 'Issued', 'Status', 'Total', 'Outstanding']];
        foreach ($report['categories'] as $category) {
            foreach ($category['invoices'] as $invoice) {
                $rows[] = [
                    $category['name'],
                    $invoice['invoice_number'],
                    $invoice['client'],
                    $invoice['project'] ?? '',
                    $invoice['issued_on'],
                    ucfirst($invoice['status']),
                    number_format($invoice['total'], 2, '.', ''),
                    number_format($invoice['balance'], 2, '.', ''),
                ];
            }
        }

        return $rows;
    }
}
