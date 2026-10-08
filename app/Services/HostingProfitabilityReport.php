<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSplit;
use App\Models\Invoice;
use App\Models\InvoiceCategory;
use Illuminate\Support\Carbon;

// Hosting profitability for a year (Bookkeeping > Hosting profitability):
// for each client, what they were invoiced for hosting -- invoices in the
// Hosting category issued that year, sent or paid, with what's been paid
// beside it -- against their share of the hosting bills (expense splits on
// expenses dated that year), and the margin; month by month too. The
// share of a bill split as Not billed (servers nobody is billed for) and
// hosting bills not split yet are shown on their own, so no cost goes
// missing.
class HostingProfitabilityReport
{
    public static function forYear(int $year): array
    {
        $hostingInvoiceCategory = InvoiceCategory::where('name', 'Hosting')->value('id');
        $hostingExpenseCategory = ExpenseCategory::where('name', 'Hosting')->value('id');

        $invoices = $hostingInvoiceCategory
            ? Invoice::where('category_id', $hostingInvoiceCategory)
                ->whereIn('status', ['sent', 'paid'])
                ->whereYear('issued_on', $year)
                ->with(['items', 'payments', 'company:id,name'])
                ->get()
            : collect();

        $splits = ExpenseSplit::whereHas('expense', fn ($q) => $q->whereYear('date', $year))
            ->with(['expense:id,date', 'company:id,name'])
            ->get();

        $unassigned = $hostingExpenseCategory
            ? (float) Expense::where('category_id', $hostingExpenseCategory)->whereYear('date', $year)->doesntHave('splits')->sum('amount')
            : 0.0;

        $notBilled = round((float) $splits->whereNull('company_id')->sum('amount'), 2);

        $companies = $invoices->pluck('company')->merge($splits->pluck('company'))->filter()->unique('id')->sortBy('name');

        $clients = $companies->map(function ($company) use ($invoices, $splits) {
            $theirs = $invoices->where('company_id', $company->id);
            $costs = $splits->where('company_id', $company->id);

            $months = collect(range(1, 12))->map(function ($m) use ($theirs, $costs) {
                $invoiced = $theirs->filter(fn (Invoice $i) => $i->issued_on->month === $m)->sum(fn (Invoice $i) => $i->total());
                $cost = (float) $costs->filter(fn (ExpenseSplit $s) => Carbon::parse($s->expense->date)->month === $m)->sum('amount');

                return ['month' => $m, 'invoiced' => round($invoiced, 2), 'cost' => round($cost, 2)];
            })->values();

            $invoiced = round($theirs->sum(fn (Invoice $i) => $i->total()), 2);
            $outstanding = round($theirs->where('status', 'sent')->sum(fn (Invoice $i) => $i->remainingBalance()), 2);
            $cost = round((float) $costs->sum('amount'), 2);

            return [
                'id' => $company->id,
                'name' => $company->name,
                'invoiced' => $invoiced,
                'paid' => round($invoiced - $outstanding, 2),
                'cost' => $cost,
                'margin' => round($invoiced - $cost, 2),
                'margin_percent' => $invoiced > 0 ? round(($invoiced - $cost) / $invoiced * 100, 1) : null,
                'months' => $months->filter(fn ($m) => $m['invoiced'] > 0 || $m['cost'] > 0)->values()->all(),
            ];
        })->values();

        $invoiced = round($clients->sum('invoiced'), 2);
        $cost = round($clients->sum('cost') + $notBilled + $unassigned, 2);

        return [
            'year' => $year,
            'clients' => $clients->all(),
            'not_billed' => $notBilled,
            'unassigned' => round($unassigned, 2),
            'totals' => [
                'invoiced' => $invoiced,
                'paid' => round($clients->sum('paid'), 2),
                'cost' => $cost,
                'margin' => round($invoiced - $cost, 2),
                'margin_percent' => $invoiced > 0 ? round(($invoiced - $cost) / $invoiced * 100, 1) : null,
            ],
        ];
    }

    // A row per client, then any not-billed and unassigned cost and the
    // year's total.
    public static function csvRows(array $report): array
    {
        $money = fn ($n) => number_format((float) $n, 2, '.', '');
        $rows = [['Client', 'Invoiced', 'Paid', 'Cost', 'Margin', 'Margin %']];
        foreach ($report['clients'] as $c) {
            $rows[] = [$c['name'], $money($c['invoiced']), $money($c['paid']), $money($c['cost']), $money($c['margin']), $c['margin_percent'] ?? ''];
        }
        if ($report['not_billed'] > 0) {
            $rows[] = ['Not billed (servers no client pays for)', '', '', $money($report['not_billed']), $money(-$report['not_billed']), ''];
        }
        if ($report['unassigned'] > 0) {
            $rows[] = ['Unassigned hosting cost', '', '', $money($report['unassigned']), $money(-$report['unassigned']), ''];
        }
        $t = $report['totals'];
        $rows[] = ["Total {$report['year']}", $money($t['invoiced']), $money($t['paid']), $money($t['cost']), $money($t['margin']), $t['margin_percent'] ?? ''];

        return $rows;
    }
}
