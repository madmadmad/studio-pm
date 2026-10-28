<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\CashRequirementSummary;
use App\Services\Payroll\PayrollReportException;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Entries made by hand (Bookkeeping > Journal): a general journal entry,
// and the everyday ones that have their own simpler forms -- a transfer
// (the monthly card payment), a Stripe payout, a payroll run. Each goes
// through the Ledger, which refuses an unbalanced entry or a locked date
// (422). Amounts arrive as decimals and post as cents.
class JournalEntryController extends Controller
{
    use ServesPrivateFile;

    public function __construct(private Ledger $ledger) {}

    // Any balanced set of lines.
    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $line) => [
            'account' => (int) $line['account_id'],
            'debit_cents' => Money::toCents($line['debit'] ?? null),
            'credit_cents' => Money::toCents($line['credit'] ?? null),
            'company_id' => $line['company_id'] ?? null,
            'description' => $line['description'] ?? null,
        ], $data['lines']);

        return $this->posted($this->ledger->post($data['entry_date'], $lines, $data['memo']));
    }

    // Money moved between the studio's own accounts: paying the card from
    // checking, an owner putting money in or taking a distribution, sending
    // the state its sales tax. Never an expense.
    public function transfer(Request $request)
    {
        $balanceSheet = Rule::exists('accounts', 'id')->whereIn('type', [Account::ASSET, Account::LIABILITY, Account::EQUITY]);
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'from_account_id' => ['required', 'integer', $balanceSheet],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', $balanceSheet],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'memo' => ['nullable', 'string', 'max:255'],
        ], ['to_account_id.different' => 'Pick two different accounts.']);

        $from = Account::findOrFail($data['from_account_id']);
        $to = Account::findOrFail($data['to_account_id']);
        $cents = Money::toCents($data['amount']);

        return $this->posted($this->ledger->post($data['entry_date'], [
            ['account' => $to, 'debit_cents' => $cents],
            ['account' => $from, 'credit_cents' => $cents],
        ], ($data['memo'] ?? null) ?: "Transfer from {$from->name} to {$to->name}"));
    }

    // Stripe paying out to the bank: the deposit moves out of Stripe
    // Clearing into checking. Fees are normally booked with each payment
    // already; any that weren't (the lookup failed) are entered here and
    // come out of clearing too.
    public function payout(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'deposited' => ['required', 'numeric', 'min:0.01'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $deposited = Money::toCents($data['deposited']);
        $fees = Money::toCents($data['fees'] ?? null);

        return $this->posted($this->ledger->post($data['entry_date'], array_values(array_filter([
            ['account' => 'checking', 'debit_cents' => $deposited],
            $fees > 0 ? ['account' => 'merchant_fees', 'debit_cents' => $fees] : null,
            ['account' => 'stripe_clearing', 'credit_cents' => $deposited + $fees],
        ])), ($data['memo'] ?? null) ?: 'Stripe payout'));
    }

    // One pay run, from the payroll provider's (Data Service's) Cash
    // Requirement Summary -- read from its PDF (readPayrollReport fills the
    // form) or typed in from it. The costs are gross wages and the
    // employer's taxes and retirement contributions. What left checking is
    // Data Service's Total Cash Requirement and American Funds' retirement
    // draw, each its own line to match the bank statement; the provider
    // draws everything it withholds, so there are no withholding payables.
    // The employees' health insurance deduction stayed in checking: it goes
    // back against Health & Life Insurance, as their share of the premiums.
    // Costs must equal those three. With the report attached, the amounts
    // must be the ones it gives, it's kept with the entry, and a second
    // report for the same check date is refused unless asked for.
    public function payroll(Request $request)
    {
        $costs = ['wages', 'payroll_taxes', 'retirement_expense'];
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys([...$costs, 'retirement_debit', 'health_deduction'], ['nullable', 'numeric', 'min:0']),
            'payroll_debit' => ['required', 'numeric', 'min:0.01'],
            'report' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'allow_duplicate' => ['nullable', 'boolean'],
        ], [
            'payroll_debit.required' => 'Enter the Total Cash Requirement Data Service took from checking.',
            'payroll_debit.min' => 'Enter the Total Cash Requirement Data Service took from checking.',
        ]);
        $cents = fn (string $key) => Money::toCents($data[$key] ?? null);

        $costCents = array_sum(array_map($cents, $costs));
        abort_if($costCents === 0, 422, 'Enter at least one payroll amount.');
        $outCents = $cents('payroll_debit') + $cents('retirement_debit') + $cents('health_deduction');
        abort_unless($costCents === $outCents, 422, sprintf(
            'The costs come to %s but what left checking plus the health deduction comes to %s. Check the amounts against the payroll report.',
            '$'.number_format($costCents / 100, 2), '$'.number_format($outCents / 100, 2),
        ));

        $report = null;
        if ($request->hasFile('report')) {
            $report = $this->readReport($request->file('report')->getRealPath());
            foreach ($report->amounts() as $key => $amount) {
                abort_unless($cents($key) === Money::toCents($amount), 422, 'The amounts don’t match the attached report. Read the report again, or post without it.');
            }
            if (! ($data['allow_duplicate'] ?? false) && ($existing = $this->postedRunFor($report))) {
                abort(422, "The pay run for the {$report->checkDate->format('M j, Y')} check date is already entered (entry #{$existing->journalEntry->entry_number}).");
            }
        }

        $lines = collect($costs)
            ->map(fn (string $key) => ['account' => $key, 'debit_cents' => $cents($key)])
            ->filter(fn (array $line) => $line['debit_cents'] > 0)
            ->values()
            ->all();
        $lines[] = ['account' => 'checking', 'credit_cents' => $cents('payroll_debit'), 'description' => 'Total Cash Requirement (Data Service)'];
        if ($cents('retirement_debit') > 0) {
            $lines[] = ['account' => 'checking', 'credit_cents' => $cents('retirement_debit'), 'description' => 'American Funds retirement debit'];
        }
        if ($cents('health_deduction') > 0) {
            $lines[] = ['account' => 'health_insurance', 'credit_cents' => $cents('health_deduction'), 'description' => 'Employees\' health insurance deduction'];
        }

        $memo = ($data['memo'] ?? null) ?: ($report ? 'Payroll, check date '.$report->checkDate->format('M j, Y') : 'Payroll');

        $entry = DB::transaction(function () use ($data, $lines, $memo, $report, $request) {
            $entry = $this->ledger->post($data['entry_date'], $lines, $memo);
            if ($report) {
                $file = $request->file('report');
                PayrollRun::create([
                    'check_date' => $report->checkDate,
                    'period_date' => $report->periodDate,
                    'journal_entry_id' => $entry->id,
                    'report_path' => $file->store('payroll-reports', config('filesystems.private_disk')),
                    'report_filename' => $file->getClientOriginalName(),
                    'summary' => $report->toArray(),
                    'created_by' => $request->user()->id,
                ]);
            }

            return $entry;
        });

        return $this->posted($entry);
    }

    // Reads an uploaded Cash Requirement Summary for the payroll form: the
    // amounts to fill in, the check date, every line read (to show what
    // each amount came from), and any pay run already entered for that
    // check date. Nothing is posted or kept.
    public function readPayrollReport(Request $request)
    {
        $request->validate(['report' => ['required', 'file', 'mimes:pdf', 'max:10240']], [
            'report.mimes' => 'Choose the Cash Requirement Summary PDF.',
        ]);
        $report = $this->readReport($request->file('report')->getRealPath());
        $existing = $this->postedRunFor($report);

        return [
            'entry_date' => $report->checkDate->toDateString(),
            'memo' => 'Payroll, check date '.$report->checkDate->format('M j, Y'),
            'amounts' => $report->amounts(),
            'report' => $report->toArray(),
            'already_entered' => $existing ? ['entry_number' => $existing->journalEntry->entry_number] : null,
        ];
    }

    // The report a pay run was posted from.
    public function payrollReport(PayrollRun $payrollRun)
    {
        return $this->respondWithPrivateFile(config('filesystems.private_disk'), $payrollRun->report_path, $payrollRun->report_filename);
    }

    private function readReport(string $path): CashRequirementSummary
    {
        try {
            return CashRequirementSummary::fromPdf($path);
        } catch (PayrollReportException $e) {
            abort(422, $e->getMessage());
        }
    }

    // A pay run already posted (and not reversed) for the report's check date.
    private function postedRunFor(CashRequirementSummary $report): ?PayrollRun
    {
        return PayrollRun::with('journalEntry')
            ->whereDate('check_date', $report->checkDate)
            ->whereDoesntHave('journalEntry.reversal')
            ->first();
    }

    // Undo an entry made by hand. One the app posted for an expense or a
    // payment follows that record -- reversing it here would just be put
    // back -- so that's changed or deleted instead. Dated like the
    // original unless that's locked and another date is given.
    public function reverse(Request $request, JournalEntry $journalEntry)
    {
        $data = $request->validate(['entry_date' => ['nullable', 'date']]);
        abort_unless($journalEntry->isManual(), 422, 'This entry follows its expense or payment. Change or delete that instead.');

        $this->ledger->reverse($journalEntry, $data['entry_date'] ?? null);

        return $this->posted($journalEntry->fresh());
    }

    private function posted(JournalEntry $entry): array
    {
        return $entry->load(['lines.account', 'lines.company', 'source', 'creator', 'reverses', 'reversal', 'payrollRun'])->summary();
    }
}
