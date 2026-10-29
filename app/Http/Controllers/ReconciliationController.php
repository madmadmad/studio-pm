<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use App\Services\Reconciliation\Reconciler;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Reconciling a statement (Reconciler): start one -- with the statement's
// CSV export, if there is one -- tick lines off, add what the books are
// missing, finish; reopen the latest finished one, or drop one in progress.
// A refusal (it doesn't add up, it's finished) is a 422 with the reason.
class ReconciliationController extends Controller
{
    public function __construct(private Reconciler $reconciler) {}

    public function store(Request $request)
    {
        $keys = Reconciler::ACCOUNTS;
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereIn('system_key', $keys)],
            'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'ending_balance' => ['required', 'numeric'],
            'statement' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
        ], ['statement.mimes' => 'Choose the statement’s CSV export.']);

        $rows = null;
        if ($request->hasFile('statement')) {
            $rows = $this->readCsv($request->file('statement')->getRealPath());
            abort_if($rows === [], 422, 'That CSV has no transactions in it.');
        }

        $reconciliation = $this->reconciler->start(
            Account::findOrFail($data['account_id']),
            $data['statement_date'],
            Money::toCents($data['ending_balance']),
            $rows,
            $request->user(),
        );

        return ['id' => $reconciliation->id];
    }

    public function toggle(Request $request, BankReconciliation $reconciliation, JournalLine $line)
    {
        $data = $request->validate(['cleared' => ['required', 'boolean']]);
        $this->reconciler->toggle($reconciliation, $line, $data['cleared']);

        return $this->reconciler->summary($reconciliation);
    }

    public function addMissing(BankReconciliation $reconciliation, int $row)
    {
        $this->reconciler->addMissing($reconciliation, $row);

        return response()->noContent();
    }

    public function finish(Request $request, BankReconciliation $reconciliation)
    {
        $this->reconciler->finish($reconciliation, $request->user());

        return response()->noContent();
    }

    public function reopen(BankReconciliation $reconciliation)
    {
        $this->reconciler->reopen($reconciliation);

        return response()->noContent();
    }

    public function destroy(BankReconciliation $reconciliation)
    {
        $this->reconciler->cancel($reconciliation);

        return response()->noContent();
    }

    // The export's rows by their headers (the bank's and Capital One's,
    // as StatementMatch reads them).
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), fgetcsv($handle) ?: []);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, $row);
            }
        }
        fclose($handle);

        abort_unless(in_array('Transaction Date', $headers, true) || in_array('Date', $headers, true), 422, 'That doesn’t look like a bank or Capital One export (no Date column).');

        return $rows;
    }
}
