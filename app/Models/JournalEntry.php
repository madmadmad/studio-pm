<?php

namespace App\Models;

use App\Exceptions\LedgerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// One posted, balanced transaction in the ledger. Written only by
// App\Services\Ledger and immutable once posted: a mistake is undone by a
// reversing entry (reverses_entry_id), never an edit or a delete.
class JournalEntry extends Model
{
    protected $fillable = ['entry_number', 'entry_date', 'memo', 'source_type', 'source_id', 'reverses_entry_id', 'posted_at', 'created_by'];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            throw new LedgerException("Journal entry #{$entry->entry_number} is posted and can't be changed. Reverse it instead.");
        });

        static::deleting(function (JournalEntry $entry) {
            throw new LedgerException("Journal entry #{$entry->entry_number} is posted and can't be deleted. Reverse it instead.");
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    // The Expense, Payment... that caused this entry, if any.
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    // The entry this one undoes.
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_entry_id');
    }

    // The entry that undid this one, once it's been reversed.
    public function reversal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reverses_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }

    public function totalCents(): int
    {
        return (int) $this->lines->sum('debit_cents');
    }

    // Entries the app posted for a record (an expense, a payment) follow
    // that record; only entries made by hand are reversed by hand.
    public function isManual(): bool
    {
        return $this->source_type === null;
    }

    // What the Journal screen shows: the entry, its lines, where it came
    // from (with a link), and the entries it reverses or was reversed by.
    // Expects lines.account, lines.company, source, creator, reverses and
    // reversal loaded.
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date->toDateString(),
            'memo' => $this->memo,
            'total_cents' => $this->totalCents(),
            'manual' => $this->isManual(),
            'source' => $this->sourceSummary(),
            'created_by' => $this->creator?->name,
            'posted_at' => $this->posted_at->toIso8601String(),
            'reverses' => $this->reverses?->only('id', 'entry_number'),
            'reversed_by' => $this->reversal?->only('id', 'entry_number'),
            'lines' => $this->lines->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'account' => $line->account->only('id', 'code', 'name'),
                'company' => $line->company?->only('id', 'name'),
                'debit_cents' => $line->debit_cents,
                'credit_cents' => $line->credit_cents,
                'description' => $line->description,
            ])->values()->all(),
        ];
    }

    // ['kind', 'label', 'url'] for the record behind an entry, or null for
    // one made by hand. A record deleted since still names its kind.
    private function sourceSummary(): ?array
    {
        if ($this->isManual()) {
            return null;
        }

        $source = $this->source;

        return match ($this->source_type) {
            (new Expense)->getMorphClass() => ['kind' => 'Expense', 'label' => $source?->name ?? 'Deleted expense', 'url' => '/expenses'],
            (new Payment)->getMorphClass() => ['kind' => 'Payment', 'label' => $source ? "Invoice #{$source->invoice?->invoice_number}" : 'Deleted payment', 'url' => $source ? "/invoices/{$source->invoice_id}" : null],
            (new Transaction)->getMorphClass() => ['kind' => 'Income', 'label' => $source?->description ?: 'Other income', 'url' => '/bookkeeping'],
            default => ['kind' => class_basename($this->source_type), 'label' => (string) $this->source_id, 'url' => null],
        };
    }
}
