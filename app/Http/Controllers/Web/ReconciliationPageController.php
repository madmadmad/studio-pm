<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use App\Services\Reconciliation\Reconciler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Bookkeeping > Reconcile: each account's statements, and one statement
// being (or once) ticked off against the ledger. The accountant sees them
// all, and changes nothing (RestrictAccountant).
class ReconciliationPageController extends Controller
{
    public function __construct(private Reconciler $reconciler) {}

    public function index(): Response
    {
        $accounts = collect(Reconciler::ACCOUNTS)->map(function (string $key) {
            $account = Account::forKey($key);
            $last = $this->reconciler->latestCompleted($account);

            return [
                'id' => $account->id,
                'name' => $account->name,
                'is_card' => $key === 'capital_one_card',
                'last' => $last ? ['date' => $last->statement_date->toDateString(), 'ending_cents' => $last->statement_ending_balance_cents] : null,
                'open_id' => $this->reconciler->open($account)?->id,
                'history' => BankReconciliation::with('completer:id,name')->where('account_id', $account->id)
                    ->orderByDesc('statement_date')->limit(24)->get()
                    ->map(fn (BankReconciliation $r) => [
                        'id' => $r->id,
                        'date' => $r->statement_date->toDateString(),
                        'ending_cents' => $r->statement_ending_balance_cents,
                        'completed_at' => $r->completed_at?->toIso8601String(),
                        'completed_by' => $r->completer?->name,
                    ]),
            ];
        });

        return Inertia::render('Bookkeeping/Reconcile', ['accounts' => $accounts]);
    }

    public function show(Request $request, BankReconciliation $reconciliation): Response
    {
        $reconciliation->load('account', 'creator:id,name', 'completer:id,name');
        $sign = Reconciler::sign($reconciliation->account);
        $lines = $reconciliation->isCompleted()
            ? $reconciliation->lines()->with('entry')->get()->sortBy(fn ($l) => $l->entry->entry_date->toDateString())->values()
            : $this->reconciler->candidates($reconciliation);
        $latest = $this->reconciler->latestCompleted($reconciliation->account);

        return Inertia::render('Bookkeeping/Reconciliation', [
            'reconciliation' => [
                'id' => $reconciliation->id,
                'account' => $reconciliation->account->name,
                'is_card' => $reconciliation->account->system_key === 'capital_one_card',
                'statement_date' => $reconciliation->statement_date->toDateString(),
                'completed_at' => $reconciliation->completed_at?->toIso8601String(),
                'completed_by' => $reconciliation->completer?->name,
                'started_by' => $reconciliation->creator?->name,
                'can_reopen' => $reconciliation->isCompleted() && $latest?->id === $reconciliation->id && ! $this->reconciler->open($reconciliation->account),
            ],
            'summary' => $this->reconciler->summary($reconciliation),
            'lines' => $lines->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'date' => $line->entry->entry_date->toDateString(),
                'entry_number' => $line->entry->entry_number,
                'memo' => $line->description ?: $line->entry->memo,
                'cents' => $sign * ($line->debit_cents - $line->credit_cents),
                'cleared' => $line->bank_reconciliation_id === $reconciliation->id,
            ])->values(),
            // The export's rows the books don't have yet.
            'missing' => collect($reconciliation->statement_rows ?? [])
                ->map(fn (array $row, int $i) => ['index' => $i, 'date' => $row['date'], 'description' => $row['description'], 'cents' => $sign * $row['cents']] + ['line_id' => $row['line_id']])
                ->whereNull('line_id')->values(),
            'has_export' => $reconciliation->statement_rows !== null,
        ]);
    }

    // The reconciliation report for the accountant: the statement's
    // figures, then every cleared line.
    public function csv(BankReconciliation $reconciliation): StreamedResponse
    {
        $reconciliation->load('account');
        $sign = Reconciler::sign($reconciliation->account);
        $summary = $this->reconciler->summary($reconciliation);
        $money = fn (int $cents) => number_format($cents / 100, 2, '.', '');
        $name = 'reconciliation-'.str($reconciliation->account->name)->slug().'-'.$reconciliation->statement_date->toDateString().'.csv';

        return response()->streamDownload(function () use ($reconciliation, $sign, $summary, $money) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account', $reconciliation->account->name]);
            fputcsv($out, ['Statement date', $reconciliation->statement_date->toDateString()]);
            fputcsv($out, ['Status', $reconciliation->isCompleted() ? 'Reconciled '.$reconciliation->completed_at->toDateString() : 'In progress']);
            foreach (['Starting balance' => 'starting_cents', 'Cleared in' => 'increases_cents', 'Cleared out' => 'decreases_cents', 'Cleared balance' => 'cleared_balance_cents', 'Statement ending balance' => 'ending_cents', 'Difference' => 'difference_cents'] as $label => $key) {
                fputcsv($out, [$label, $money($summary[$key])]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Date', 'Entry', 'Description', 'Amount']);
            foreach ($reconciliation->lines()->with('entry')->get()->sortBy(fn ($l) => $l->entry->entry_date->toDateString()) as $line) {
                fputcsv($out, [$line->entry->entry_date->toDateString(), $line->entry->entry_number, $line->description ?: $line->entry->memo, $money($sign * ($line->debit_cents - $line->credit_cents))]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
