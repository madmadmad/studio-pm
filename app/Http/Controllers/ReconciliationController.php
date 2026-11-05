<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use App\Exceptions\LedgerException;
use App\Services\BonsaiImport\StatementMatch;
use App\Services\Reconciliation\Reconciler;
use App\Services\Reconciliation\Statements\BankStatement;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Reconciling a statement (Reconciler): start one -- from its PDF, or by
// hand with its CSV export if there is one -- tick lines off, add what the
// books are missing, finish; reopen the latest finished one, or drop one
// in progress. A refusal (it doesn't add up, it's finished) is a 422 with
// the reason.
class ReconciliationController extends Controller
{
    public function __construct(private Reconciler $reconciler) {}

    // A statement's PDF, read before starting: its figures, and whether it
    // picks up where the account's last statement left off.
    public function preview(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereIn('system_key', Reconciler::ACCOUNTS)],
            'statement_pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], ['statement_pdf.mimes' => 'Choose the statement’s PDF.']);
        $account = Account::findOrFail($data['account_id']);
        $statement = $this->readPdf($request, $account);
        $last = $this->reconciler->latestCompleted($account);

        return $statement->toArray() + [
            'last_date' => $last?->statement_date->toDateString(),
            'cleared_cents' => $this->reconciler->clearedBalance($account),
        ];
    }

    public function store(Request $request)
    {
        $keys = Reconciler::ACCOUNTS;
        $fromPdf = $request->hasFile('statement_pdf');
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereIn('system_key', $keys)],
            'statement_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'statement_date' => [$fromPdf ? 'nullable' : 'required', 'date', 'before_or_equal:today'],
            'ending_balance' => [$fromPdf ? 'nullable' : 'required', 'numeric'],
            'statement' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
        ], ['statement.mimes' => 'Choose the statement’s CSV export.', 'statement_pdf.mimes' => 'Choose the statement’s PDF.']);
        $account = Account::findOrFail($data['account_id']);

        if ($fromPdf) {
            $statement = $this->readPdf($request, $account);
            $file = $request->file('statement_pdf');
            $reconciliation = $this->reconciler->start($account, $statement->statementDate, $statement->endingCents, $statement->rows, $request->user(), [
                'statement_file_path' => $file->store('bank-statements', config('filesystems.private_disk')),
                'statement_file_name' => $file->getClientOriginalName(),
                'statement_beginning_cents' => $statement->beginningCents,
                'statement_balances' => $statement->dailyBalances ?: null,
                'whole_statement' => true,
            ]);

            return ['id' => $reconciliation->id];
        }

        $rows = null;
        if ($request->hasFile('statement')) {
            $rows = $this->readCsv($request->file('statement')->getRealPath());
            abort_if($rows === [], 422, 'That CSV has no transactions in it.');
        }

        $reconciliation = $this->reconciler->start(
            $account,
            $data['statement_date'],
            Money::toCents($data['ending_balance']),
            $rows === null ? null : StatementMatch::rows($rows),
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

    // The uploaded PDF, read -- and refused if it's another account's
    // statement, or dated in the future.
    private function readPdf(Request $request, Account $account): BankStatement
    {
        $statement = BankStatement::fromPdf($request->file('statement_pdf')->getRealPath());
        if ($statement->accountKey !== $account->system_key) {
            throw new LedgerException("That’s a {$statement->bank} statement (account ending {$statement->accountLast4}), not {$account->name}’s.");
        }
        if ($statement->statementDate > today()->toDateString()) {
            throw new LedgerException('That statement ends in the future.');
        }

        return $statement;
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
