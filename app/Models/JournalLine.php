<?php

namespace App\Models;

use App\Exceptions\LedgerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One side of a journal entry: a debit or a credit (never both, never
// zero -- the database checks) to one account, in cents. Immutable like
// its entry, except for marking it reconciled against a bank statement.
class JournalLine extends Model
{
    protected $fillable = ['journal_entry_id', 'account_id', 'debit_cents', 'credit_cents', 'description', 'company_id', 'bank_reconciliation_id'];

    protected $casts = [
        'debit_cents' => 'integer',
        'credit_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (JournalLine $line) {
            $changed = array_diff(array_keys($line->getDirty()), ['bank_reconciliation_id', 'updated_at']);
            if ($changed !== []) {
                throw new LedgerException("A posted journal line can't be changed. Reverse its entry instead.");
            }
        });

        static::deleting(function () {
            throw new LedgerException("A posted journal line can't be deleted. Reverse its entry instead.");
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }
}
