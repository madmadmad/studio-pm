<?php

namespace App\Services\Reconciliation;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BonsaiImport\StatementMatch;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Monthly bank and card reconciliation (CPA review, Oct 2026). A
// statement's end date and ending balance are entered (and, optionally,
// its CSV export uploaded); the account's ledger lines up to that date
// that no finished statement covers are ticked off as cleared -- the
// export's rows ticking their matches -- until what's cleared, added to
// where the last statement ended, comes to the statement's ending balance.
// Then it's finished, and its lines are fixed (Ledger refuses to reverse
// them) until it's undone.
//
// Balances and amounts are as the statement shows them: positive is money
// in checking, or owed on the card. A ledger line's change to the account
// (debit positive) is turned to that by sign().
class Reconciler
{
    // The accounts reconciled each month.
    public const ACCOUNTS = ['checking', 'capital_one_card'];

    // How far apart a statement row and its ledger line can be dated.
    public const MATCH_DAYS = 10;

    public function __construct(private Ledger $ledger) {}

    // +1 for checking (a debit adds to it), -1 for the card (a credit is
    // more owed).
    public static function sign(Account $account): int
    {
        return $account->isDebitNormal() ? 1 : -1;
    }

    // What the finished statements say the account holds: where the next
    // one starts.
    public function clearedBalance(Account $account): int
    {
        $net = (int) JournalLine::where('account_id', $account->id)
            ->whereHas('bankReconciliation', fn ($q) => $q->whereNotNull('completed_at'))
            ->selectRaw('coalesce(sum(debit_cents), 0) - coalesce(sum(credit_cents), 0) as net')
            ->value('net');

        return self::sign($account) * $net;
    }

    public function latestCompleted(Account $account): ?BankReconciliation
    {
        return BankReconciliation::where('account_id', $account->id)->whereNotNull('completed_at')->latest('statement_date')->latest('id')->first();
    }

    public function open(Account $account): ?BankReconciliation
    {
        return BankReconciliation::where('account_id', $account->id)->whereNull('completed_at')->first();
    }

    /**
     * @param  list<array<string, string>>|null  $csvRows  the export's rows, as read from the CSV
     */
    public function start(Account $account, string $statementDate, int $endingCents, ?array $csvRows, User $by): BankReconciliation
    {
        if ($this->open($account)) {
            throw new LedgerException("A reconciliation for {$account->name} is already in progress. Finish or cancel it first.");
        }
        $last = $this->latestCompleted($account);
        if ($last && $statementDate <= $last->statement_date->toDateString()) {
            throw new LedgerException("{$account->name} is reconciled through {$last->statement_date->format('M j, Y')}. Pick a later statement date.");
        }

        $rows = null;
        if ($csvRows !== null) {
            $from = $last?->statement_date->copy()->addDay()->toDateString();
            $rows = collect(StatementMatch::rows($csvRows))
                // Not yet posted ("… (Pending)") isn't on a statement.
                ->filter(fn (array $r) => $r['cents'] !== 0 && $r['date'] <= $statementDate && (! $from || $r['date'] >= $from) && ! str_contains($r['description'], '(Pending)'))
                ->map(fn (array $r) => ['date' => $r['date'], 'cents' => $r['cents'], 'description' => $r['description'], 'line_id' => null])
                ->values()->all();
        }

        $reconciliation = BankReconciliation::create([
            'account_id' => $account->id,
            'statement_date' => $statementDate,
            'starting_balance_cents' => $this->clearedBalance($account),
            'statement_ending_balance_cents' => $endingCents,
            'statement_rows' => $rows,
            'created_by' => $by->id,
        ]);
        $this->autoMatch($reconciliation);

        return $reconciliation->fresh();
    }

    // The account's lines a statement can clear: up to its date, not on a
    // finished statement. Oldest first.
    public function candidates(BankReconciliation $r): Collection
    {
        return JournalLine::query()
            ->with('entry')
            ->where('account_id', $r->account_id)
            ->where(fn ($q) => $q->whereNull('bank_reconciliation_id')->orWhere('bank_reconciliation_id', $r->id))
            ->whereHas('entry', fn ($q) => $q->whereDate('entry_date', '<=', $r->statement_date))
            ->get()
            ->sortBy(fn (JournalLine $line) => $line->entry->entry_date->toDateString().sprintf('%010d', $line->id))
            ->values();
    }

    // Each export row ticks the line for the same amount, the closest date
    // first, within MATCH_DAYS.
    public function autoMatch(BankReconciliation $r): void
    {
        $rows = $r->statement_rows;
        if (! $rows) {
            return;
        }
        $free = $this->candidates($r)->filter(fn (JournalLine $line) => ! collect($rows)->contains('line_id', $line->id))->all();

        DB::transaction(function () use ($r, &$rows, &$free) {
            foreach ($rows as $i => $row) {
                if ($row['line_id']) {
                    continue;
                }
                $best = null;
                foreach ($free as $key => $line) {
                    $days = abs(Carbon::parse($row['date'])->diffInDays($line->entry->entry_date));
                    if ($line->debit_cents - $line->credit_cents === $row['cents'] && $days <= self::MATCH_DAYS && ($best === null || $days < $best[1])) {
                        $best = [$key, $days];
                    }
                }
                if ($best !== null) {
                    $line = $free[$best[0]];
                    unset($free[$best[0]]);
                    $rows[$i]['line_id'] = $line->id;
                    $line->update(['bank_reconciliation_id' => $r->id]);
                }
            }
            $r->update(['statement_rows' => $rows]);
        });
    }

    public function toggle(BankReconciliation $r, JournalLine $line, bool $cleared): void
    {
        $this->assertOpen($r);
        if ($line->account_id !== $r->account_id || ! $this->candidates($r)->contains('id', $line->id)) {
            throw new LedgerException('That entry isn’t on this statement’s account and dates.');
        }
        $line->update(['bank_reconciliation_id' => $cleared ? $r->id : null]);
    }

    // [starting, cleared in, cleared out, cleared balance, ending, difference]
    // as the statement shows them.
    public function summary(BankReconciliation $r): array
    {
        $sign = self::sign($r->account);
        $cleared = JournalLine::where('bank_reconciliation_id', $r->id)->get()->map(fn (JournalLine $l) => $sign * ($l->debit_cents - $l->credit_cents));
        $balance = $r->starting_balance_cents + $cleared->sum();

        return [
            'starting_cents' => $r->starting_balance_cents,
            'increases_cents' => (int) $cleared->filter(fn ($c) => $c > 0)->sum(),
            'decreases_cents' => (int) -$cleared->filter(fn ($c) => $c < 0)->sum(),
            'cleared_balance_cents' => $balance,
            'ending_cents' => $r->statement_ending_balance_cents,
            'difference_cents' => $r->statement_ending_balance_cents - $balance,
        ];
    }

    // A statement row the books don't have, added: a charge or a payment
    // out of checking as an expense, a credit to the card as money back, a
    // deposit into checking as other income. Uncategorized, to be sorted
    // on Expenses; its line is ticked.
    public function addMissing(BankReconciliation $r, int $rowIndex): void
    {
        $this->assertOpen($r);
        $rows = $r->statement_rows ?? [];
        $row = $rows[$rowIndex] ?? throw new LedgerException('That statement line isn’t on this reconciliation.');
        if ($row['line_id']) {
            throw new LedgerException('That statement line is already matched.');
        }

        $account = $r->account;
        $record = $account->system_key === 'checking' && $row['cents'] > 0
            ? Transaction::create([
                'type' => 'income', 'amount' => Money::fromCents($row['cents']), 'category' => 'Other income',
                'description' => $row['description'], 'occurred_on' => $row['date'], 'deposit_account_id' => $account->id,
            ])
            : Expense::create([
                'name' => $row['description'], 'amount' => Money::fromCents(-$row['cents']), 'date' => $row['date'],
                'is_billable' => false, 'billing_status' => 'unbilled', 'paid_from_account_id' => $account->id,
                'source_label' => 'Statement', 'notes' => "Added from the {$account->name} statement of {$r->statement_date->format('M j, Y')}.",
            ]);

        $line = $this->ledger->liveEntryFor($record)?->lines->firstWhere('account_id', $account->id)
            ?? throw new LedgerException('It was added, but didn’t post to the books. Check it on Expenses.');
        $line->update(['bank_reconciliation_id' => $r->id]);
        $rows[$rowIndex]['line_id'] = $line->id;
        $r->update(['statement_rows' => $rows]);
    }

    public function finish(BankReconciliation $r, User $by): void
    {
        $this->assertOpen($r);
        $difference = $this->summary($r)['difference_cents'];
        if ($difference !== 0) {
            throw new LedgerException('It’s off by $'.number_format(abs($difference) / 100, 2).'. What’s cleared has to come to the statement’s ending balance.');
        }
        $r->update(['completed_at' => now(), 'completed_by' => $by->id]);
    }

    // The latest finished statement opened again, to fix; or one in
    // progress dropped, its ticks undone.
    public function reopen(BankReconciliation $r): void
    {
        if (! $r->isCompleted() || $this->latestCompleted($r->account)?->id !== $r->id || $this->open($r->account)) {
            throw new LedgerException('Only the latest finished statement can be reopened, and only while no other is in progress.');
        }
        $r->update(['completed_at' => null, 'completed_by' => null]);
    }

    public function cancel(BankReconciliation $r): void
    {
        $this->assertOpen($r);
        DB::transaction(function () use ($r) {
            JournalLine::where('bank_reconciliation_id', $r->id)->get()->each->update(['bank_reconciliation_id' => null]);
            $r->delete();
        });
    }

    // Everything on the account through $date, as one finished statement --
    // for the history before monthly reconciling starts (the Bonsai import
    // already matched it to the statements). Refused unless the books come
    // to the statement's balance.
    public function reconcileThrough(Account $account, string $date, int $endingCents, ?User $by = null): BankReconciliation
    {
        return DB::transaction(function () use ($account, $date, $endingCents, $by) {
            $r = BankReconciliation::create([
                'account_id' => $account->id, 'statement_date' => $date,
                'starting_balance_cents' => $this->clearedBalance($account), 'statement_ending_balance_cents' => $endingCents,
                'created_by' => $by?->id,
            ]);
            $this->candidates($r)->each->update(['bank_reconciliation_id' => $r->id]);
            $difference = $this->summary($r)['difference_cents'];
            if ($difference !== 0) {
                throw new LedgerException("The books come to $".number_format(($endingCents - $difference) / 100, 2)." for {$account->name} on {$date}, not $".number_format($endingCents / 100, 2).'.');
            }
            $r->update(['completed_at' => now(), 'completed_by' => $by?->id]);

            return $r;
        });
    }

    private function assertOpen(BankReconciliation $r): void
    {
        if ($r->isCompleted()) {
            throw new LedgerException('This statement is finished. Reopen it to change it.');
        }
    }
}
