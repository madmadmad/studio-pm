<?php

namespace App\Services\Posting;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Ledger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Keeps one kind of record (an Expense, a Payment...) in step with the
// ledger, so people keep entering records as they always have and the
// journal writes itself (docs/ledger-plan.md, Phase 3).
//
// sync() is idempotent: it works out the entry the record should have,
// and only if that differs from the entry standing for it now does it
// reverse that one and post the new one. So it's safe to call as often as
// a record is touched -- an edit that doesn't change the money (a receipt,
// a renamed expense) posts nothing, and a deleted record is reversed.
abstract class Poster
{
    /** @var array<string, Account> */
    private array $accounts = [];

    public function __construct(protected Ledger $ledger) {}

    // The entry the record should have, as ['date', 'memo', 'lines'] with
    // lines in the Ledger's shape -- or null when it shouldn't post at all.
    abstract protected function draft(Model $record): ?array;

    public function sync(Model $record): ?JournalEntry
    {
        return DB::transaction(function () use ($record) {
            $live = $this->ledger->liveEntryFor($record);
            $current = $record->exists ? $record->fresh() : null;
            $draft = $current ? $this->draft($current) : null;

            if ($this->matches($live, $draft)) {
                return $live;
            }

            if ($live) {
                $this->ledger->reverse($live);
            }

            return $draft ? $this->ledger->post($draft['date'], $draft['lines'], $draft['memo'], $current) : null;
        });
    }

    // Before a record is saved or deleted: refuse the change if it would
    // have to post into, or reverse out of, a locked period. Changes that
    // don't touch the money are let through. $record holds the values
    // about to be saved; $deleting when it's about to go.
    public function guard(Model $record, bool $deleting = false): void
    {
        // A copy without cached relations, so a changed category (or the
        // like) is read afresh rather than as it was loaded.
        $pending = (clone $record)->unsetRelations();
        $live = $record->exists ? $this->ledger->liveEntryFor($record) : null;
        $draft = $deleting ? null : $this->draft($pending);

        if ($this->matches($live, $draft)) {
            return;
        }

        foreach (array_filter([$live?->entry_date, $draft['date'] ?? null]) as $date) {
            if ($period = AccountingPeriod::lockedOn($date)) {
                throw new LedgerException(sprintf(
                    'The books are locked from %s to %s, so this can\'t be changed.',
                    $period->starts_on->format('M j, Y'),
                    $period->ends_on->format('M j, Y'),
                ));
            }
        }
    }

    // An account the code depends on, by its handle.
    protected function account(string $systemKey): Account
    {
        return $this->accounts[$systemKey] ??= Account::forKey($systemKey);
    }

    // A mapped account, or the fallback when there's none or it's been
    // deactivated -- a record still posts, and the fallback (Uncategorized
    // Expense) is where it shows up to be fixed.
    protected function mapped(?Account $account, string $fallbackKey): Account
    {
        return $account && $account->is_active && ! $account->children()->exists()
            ? $account
            : $this->account($fallbackKey);
    }

    // Whether the standing entry already says what the draft says: same
    // date, same amounts to the same accounts and clients. Descriptions
    // and memos don't count.
    private function matches(?JournalEntry $live, ?array $draft): bool
    {
        if (! $live || ! $draft) {
            return ! $live && ! $draft;
        }

        $draftDate = $draft['date'] instanceof \DateTimeInterface ? $draft['date']->format('Y-m-d') : substr((string) $draft['date'], 0, 10);
        if ($live->entry_date->toDateString() !== $draftDate) {
            return false;
        }

        $key = fn (array $line) => implode(':', [$line['account_id'], $line['debit_cents'] ?? 0, $line['credit_cents'] ?? 0, $line['company_id'] ?? '']);
        $liveLines = $live->lines->map(fn ($line) => $key($line->only('account_id', 'debit_cents', 'credit_cents', 'company_id')))->sort()->values()->all();
        $draftLines = collect($draft['lines'])->map(fn ($line) => $key([
            'account_id' => $line['account'] instanceof Account ? $line['account']->id : $this->account($line['account'])->id,
        ] + $line))->sort()->values()->all();

        return $liveLines === $draftLines;
    }
}
