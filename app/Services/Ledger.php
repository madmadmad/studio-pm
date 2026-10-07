<?php

namespace App\Services;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// The only way into the ledger (docs/ledger-plan.md). Posts balanced
// entries and reverses them, each in one DB transaction, and refuses
// anything that would break the books: an unbalanced entry, a line that
// isn't exactly one positive debit or credit, an inactive account, or a
// date inside a locked period. Posted entries never change -- to correct
// one, reverse it and post the right one.
//
// A line is ['account' => Account|id|'system_key', 'debit_cents' => int]
// or 'credit_cents' => int, plus optional 'description' and 'company_id'.
// Amounts are integer cents; convert decimals with App\Support\Money.
class Ledger
{
    public function post(
        DateTimeInterface|string $date,
        array $lines,
        ?string $memo = null,
        ?Model $source = null,
        ?User $createdBy = null,
    ): JournalEntry {
        $date = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($date, $lines, $memo, $source, $createdBy) {
            $lines = $this->normalize($lines);
            $this->assertBalanced($lines);
            $this->assertOpen($date);

            return $this->write($date, $lines, $memo, $source, $createdBy);
        });
    }

    // Undo an entry with its mirror image: every debit becomes a credit and
    // back, linked by reverses_entry_id and keeping the original's source.
    // Dated like the original unless given a date -- the original's date
    // takes it back out of the period it was in.
    public function reverse(
        JournalEntry $entry,
        DateTimeInterface|string|null $date = null,
        ?string $memo = null,
        ?User $createdBy = null,
    ): JournalEntry {
        $date = Carbon::parse($date ?? $entry->entry_date)->startOfDay();

        return DB::transaction(function () use ($entry, $date, $memo, $createdBy) {
            if ($entry->isReversal()) {
                throw new LedgerException("Entry #{$entry->entry_number} is itself a reversal. Post a new entry instead of reversing it.");
            }
            if ($entry->reversal()->exists()) {
                throw new LedgerException("Entry #{$entry->entry_number} has already been reversed.");
            }
            $this->assertOpen($date);

            $lines = $entry->lines()->get()->map(fn ($line) => [
                'account_id' => $line->account_id,
                'debit_cents' => $line->credit_cents,
                'credit_cents' => $line->debit_cents,
                'description' => $line->description,
                'company_id' => $line->company_id,
            ])->all();

            return $this->write(
                $date,
                $lines,
                $memo ?? "Reverses entry #{$entry->entry_number}",
                $entry->source_type ? [$entry->source_type, $entry->source_id] : null,
                $createdBy,
                $entry->id,
            );
        });
    }

    // The entry currently standing for a source record: posted for it and
    // not reversed. Null when it has none (never posted, or reversed after
    // a delete).
    public function liveEntryFor(Model $source): ?JournalEntry
    {
        return JournalEntry::whereMorphedTo('source', $source)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversal')
            ->latest('id')
            ->first();
    }

    // Close a stretch of the books. Nothing can be posted dated inside it
    // until it's unlocked.
    public function lockPeriod(DateTimeInterface|string $startsOn, DateTimeInterface|string $endsOn, ?User $lockedBy = null): AccountingPeriod
    {
        $startsOn = Carbon::parse($startsOn)->startOfDay();
        $endsOn = Carbon::parse($endsOn)->startOfDay();
        if ($endsOn->lt($startsOn)) {
            throw new LedgerException('A period has to end on or after the day it starts.');
        }

        return AccountingPeriod::create([
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'locked_at' => now(),
            'locked_by' => ($lockedBy ?? auth()->user())?->id,
        ]);
    }

    public function unlockPeriod(AccountingPeriod $period): AccountingPeriod
    {
        $period->update(['locked_at' => null, 'locked_by' => null]);

        return $period;
    }

    // $source is a model, or [type, id] when copied from another entry.
    private function write(Carbon $date, array $lines, ?string $memo, Model|array|null $source, ?User $createdBy, ?int $reverses = null): JournalEntry
    {
        [$sourceType, $sourceId] = match (true) {
            $source instanceof Model => [$source->getMorphClass(), $source->getKey()],
            is_array($source) => $source,
            default => [null, null],
        };

        $entry = JournalEntry::create([
            'entry_number' => $this->nextNumber(),
            'entry_date' => $date,
            'memo' => $memo,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reverses_entry_id' => $reverses,
            'posted_at' => now(),
            'created_by' => ($createdBy ?? auth()->user())?->id,
        ]);
        $entry->lines()->createMany($lines);

        return $entry->load('lines');
    }

    // Numbers run on from the highest, with no gaps from deletes (there
    // are none). The lock serializes concurrent posts on MySQL; SQLite
    // already writes one transaction at a time.
    private function nextNumber(): int
    {
        return (int) JournalEntry::lockForUpdate()->max('entry_number') + 1;
    }

    // Each line resolved to an active account and exactly one positive
    // side, in the columns journal_lines stores.
    private function normalize(array $lines): array
    {
        if (count($lines) < 2) {
            throw new LedgerException('An entry needs at least two lines.');
        }

        return array_map(function (array $line) {
            $debit = $line['debit_cents'] ?? 0;
            $credit = $line['credit_cents'] ?? 0;
            if (! is_int($debit) || ! is_int($credit)) {
                throw new LedgerException('Line amounts are whole cents.');
            }
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                throw new LedgerException('Each line is either a debit or a credit, greater than zero.');
            }

            return [
                'account_id' => $this->account($line['account'] ?? $line['account_id'] ?? null)->id,
                'debit_cents' => $debit,
                'credit_cents' => $credit,
                'description' => $line['description'] ?? null,
                'company_id' => $line['company_id'] ?? null,
            ];
        }, $lines);
    }

    private function account(Account|int|string|null $account): Account
    {
        $resolved = match (true) {
            $account instanceof Account => $account,
            is_int($account) => Account::find($account),
            is_string($account) => Account::where('system_key', $account)->first(),
            default => null,
        };

        if (! $resolved) {
            throw new LedgerException('A line names an account that doesn\'t exist'.(is_string($account) ? " (\"{$account}\")." : '.'));
        }
        if ($resolved->children()->exists()) {
            throw new LedgerException("\"{$resolved->name}\" is a group heading. Post to one of the accounts under it.");
        }
        if (! $resolved->is_active) {
            throw new LedgerException("\"{$resolved->name}\" is inactive. Reactivate it or post to another account.");
        }

        return $resolved;
    }

    private function assertBalanced(array $lines): void
    {
        $debits = array_sum(array_column($lines, 'debit_cents'));
        $credits = array_sum(array_column($lines, 'credit_cents'));

        if ($debits !== $credits) {
            throw new LedgerException(sprintf(
                'Debits ($%s) and credits ($%s) don\'t balance.',
                number_format($debits / 100, 2),
                number_format($credits / 100, 2),
            ));
        }
    }

    private function assertOpen(Carbon $date): void
    {
        if ($period = AccountingPeriod::lockedOn($date)) {
            throw new LedgerException(sprintf(
                'The books are locked from %s to %s, so nothing can be dated %s.',
                $period->starts_on->format('M j, Y'),
                $period->ends_on->format('M j, Y'),
                $date->format('M j, Y'),
            ));
        }
    }
}
