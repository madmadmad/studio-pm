<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\LedgerReports\BalanceSheet;
use App\Services\LedgerReports\GeneralLedger;
use App\Services\LedgerReports\ProfitAndLoss;
use App\Services\LedgerReports\TrialBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// The reports built from the ledger (Bookkeeping > Ledger reports), for
// any dates -- a range (?from=&to=) or a day (?to=) -- each with a CSV.
// The year-based reports next to them (BookkeepingPageController) are
// left as they were.
class LedgerReportPageController extends Controller
{
    use StreamsCsv;

    public function generalLedger(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Bookkeeping/GeneralLedger', [
            'report' => GeneralLedger::for($from, $to, $this->accountId($request)),
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'name', 'type', 'system_key', 'parent_id', 'is_active']),
        ]);
    }

    public function generalLedgerCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        return $this->csv("general-ledger-{$from->toDateString()}-to-{$to->toDateString()}.csv", GeneralLedger::csvRows(GeneralLedger::for($from, $to, $this->accountId($request))));
    }

    public function trialBalance(Request $request): Response
    {
        return Inertia::render('Bookkeeping/TrialBalance', ['report' => TrialBalance::asOf($this->asOf($request))]);
    }

    public function trialBalanceCsv(Request $request): StreamedResponse
    {
        $asOf = $this->asOf($request);

        return $this->csv("trial-balance-{$asOf->toDateString()}.csv", TrialBalance::csvRows(TrialBalance::asOf($asOf)));
    }

    public function profitLoss(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Bookkeeping/LedgerProfitLoss', ['report' => ProfitAndLoss::for($from, $to)]);
    }

    public function profitLossCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        return $this->csv("profit-and-loss-{$from->toDateString()}-to-{$to->toDateString()}.csv", ProfitAndLoss::csvRows(ProfitAndLoss::for($from, $to)));
    }

    public function balanceSheet(Request $request): Response
    {
        return Inertia::render('Bookkeeping/BalanceSheet', ['report' => BalanceSheet::asOf($this->asOf($request))]);
    }

    public function balanceSheetCsv(Request $request): StreamedResponse
    {
        $asOf = $this->asOf($request);

        return $this->csv("balance-sheet-{$asOf->toDateString()}.csv", BalanceSheet::csvRows(BalanceSheet::asOf($asOf)));
    }

    // ?from= and ?to=, this year to date when they're missing, and in
    // order however they were given.
    private function range(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = Carbon::parse($request->query('from', now()->startOfYear()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->toDateString()))->startOfDay();

        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    // ?to=, today when it's missing.
    private function asOf(Request $request): Carbon
    {
        $request->validate(['to' => ['nullable', 'date']]);

        return Carbon::parse($request->query('to', now()->toDateString()))->startOfDay();
    }

    private function accountId(Request $request): ?int
    {
        return $request->integer('account') ?: null;
    }
}
