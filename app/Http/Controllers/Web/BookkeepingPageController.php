<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
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

    private function reportYear(Request $request): int
    {
        $year = (int) $request->query('year', now()->year);

        return $year >= 2000 && $year <= now()->year ? $year : now()->year;
    }
}
