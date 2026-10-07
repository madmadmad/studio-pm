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
}
