<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\HostingProfitabilityReport;
use App\Services\InvoiceCategoryReport;
use App\Services\ProfitLossReport;
use App\Services\SalesTaxReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookkeepingPageController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month', now()->format('Y-m'));

        // Income stays here as Transaction rows (invoice payments and
        // manual entries). Expenses are tracked on the dedicated Expenses
        // page now -- this screen only reads their total for the summary.
        $transactions = Transaction::where('type', 'income')
            ->with('invoice:id,invoice_number')
            ->orderByDesc('occurred_on')
            ->get();

        return Inertia::render('Bookkeeping/Index', [
            'transactions' => $transactions,
            'summary' => Transaction::monthlySummary($month),
            // The Financial reports card's year picker.
            'reportYears' => $this->reportYears(),
            // The chart: this year, month by month.
            'year' => ['year' => (int) substr($month, 0, 4), 'months' => Transaction::yearSeries((int) substr($month, 0, 4))],
        ]);
    }

    // The monthly sales tax report for a year (this year by default).
    public function salesTax(Request $request): Response
    {
        $year = $this->reportYear($request);

        return Inertia::render('Bookkeeping/SalesTax', [
            'report' => SalesTaxReport::forYear($year),
            // The years there's income in, to switch between.
            'years' => Transaction::where('type', 'income')->pluck('occurred_on')
                ->map(fn ($date) => $date->year)
                ->push(now()->year)
                ->unique()->sortDesc()->values(),
        ]);
    }

    // The same report as a CSV file, to file or hand to an accountant.
    public function salesTaxCsv(Request $request): StreamedResponse
    {
        $year = $this->reportYear($request);
        $rows = SalesTaxReport::csvRows(SalesTaxReport::forYear($year));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "sales-tax-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    // Invoices by category for a year (this year by default).
    public function invoiceCategories(Request $request): Response
    {
        return Inertia::render('Bookkeeping/InvoiceCategories', [
            'report' => InvoiceCategoryReport::forYear($this->reportYear($request)),
            // The years with issued invoices, to switch between.
            'years' => Invoice::whereIn('status', ['sent', 'paid'])->whereNotNull('issued_on')->pluck('issued_on')
                ->map(fn ($date) => $date->year)
                ->push(now()->year)
                ->unique()->sortDesc()->values(),
        ]);
    }

    public function invoiceCategoriesCsv(Request $request): StreamedResponse
    {
        $year = $this->reportYear($request);
        $rows = InvoiceCategoryReport::csvRows(InvoiceCategoryReport::forYear($year));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "invoices-by-category-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    // Hosting profitability for a year (this year by default).
    public function hosting(Request $request): Response
    {
        return Inertia::render('Bookkeeping/Hosting', [
            'report' => HostingProfitabilityReport::forYear($this->reportYear($request)),
            'years' => Invoice::whereIn('status', ['sent', 'paid'])->whereNotNull('issued_on')->pluck('issued_on')
                ->map(fn ($date) => $date->year)
                ->push(now()->year)
                ->unique()->sortDesc()->values(),
        ]);
    }

    public function hostingCsv(Request $request): StreamedResponse
    {
        $year = $this->reportYear($request);
        $rows = HostingProfitabilityReport::csvRows(HostingProfitabilityReport::forYear($year));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "hosting-profitability-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    // The year's profit & loss (this year by default).
    public function profitLoss(Request $request): Response
    {
        return Inertia::render('Bookkeeping/ProfitLoss', [
            'report' => ProfitLossReport::forYear($this->reportYear($request)),
            'years' => $this->reportYears(),
        ]);
    }

    public function profitLossCsv(Request $request): StreamedResponse
    {
        $year = $this->reportYear($request);

        return $this->csv("profit-and-loss-{$year}.csv", ProfitLossReport::csvRows(ProfitLossReport::forYear($year)));
    }

    // Every invoice issued in the year, for the accountant or an audit.
    public function invoicesCsv(Request $request): StreamedResponse
    {
        $year = $this->reportYear($request);
        $rows = [['Invoice', 'Client', 'Project', 'Category', 'Issued', 'Due', 'Status', 'Subtotal', 'Sales tax', 'Total', 'Paid', 'Outstanding', 'Paid on']];

        Invoice::whereYear('issued_on', $year)
            ->with(['items', 'payments', 'company:id,name', 'project:id,name'])
            ->orderBy('issued_on')->orderBy('invoice_number')
            ->get()
            ->each(function (Invoice $i) use (&$rows) {
                $outstanding = $i->status === 'paid' ? 0 : $i->remainingBalance();
                $rows[] = [
                    $i->invoice_number, $i->company?->name, $i->project?->name ?? '', $i->category?->name ?? 'Project work',
                    $i->issued_on?->toDateString(), $i->due_on?->toDateString(), ucfirst($i->status),
                    $this->money($i->subtotal()), $this->money($i->taxAmount()), $this->money($i->total()),
                    $this->money($i->status === 'draft' ? 0 : $i->total() - $outstanding),
                    $this->money($i->status === 'draft' ? 0 : $outstanding),
                    $i->payments->max('paid_at')?->toDateString() ?? '',
                ];
            });

        return $this->csv("invoices-{$year}.csv", $rows);
    }

    // Every expense dated in the year, with how it was billed or split.
    public function expensesCsv(Request $request): StreamedResponse
    {
        $year = $this->reportYear($request);
        $rows = [['Date', 'Expense', 'Category', 'Project', 'Amount', 'Billable', 'Billing status', 'Invoice', 'Split across clients', 'Source']];

        Expense::whereYear('date', $year)
            ->with(['category:id,name', 'project:id,name', 'invoice:id,invoice_number', 'splits.company:id,name'])
            ->orderBy('date')
            ->get()
            ->each(function (Expense $e) use (&$rows) {
                $rows[] = [
                    $e->date?->toDateString(), $e->name, $e->category?->name ?? '', $e->project?->name ?? '',
                    $this->money($e->amount), $e->is_billable ? 'Yes' : 'No', str_replace('_', ' ', $e->billing_status),
                    $e->invoice?->invoice_number ?? '',
                    $e->splits->map(fn ($s) => $s->company?->name.' '.$this->money($s->amount))->implode('; '),
                    $e->source_label ?? '',
                ];
            });

        return $this->csv("expenses-{$year}.csv", $rows);
    }

    private function csv(string $filename, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    // Years with any invoices, income or expenses, newest first (this year always).
    private function reportYears()
    {
        return Invoice::whereNotNull('issued_on')->pluck('issued_on')
            ->merge(Transaction::pluck('occurred_on'))
            ->merge(Expense::pluck('date'))
            ->filter()
            ->map(fn ($date) => $date->year)
            ->push(now()->year)
            ->unique()->sortDesc()->values();
    }

    private function reportYear(Request $request): int
    {
        $year = (int) $request->query('year', now()->year);

        return $year >= 2000 && $year <= now()->year ? $year : now()->year;
    }
}
