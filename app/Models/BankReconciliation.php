<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One bank or card statement reconciled against the ledger
// (App\Services\Reconciliation\Reconciler): the lines it covers point
// back at it. Open while it's being ticked off; completed once what's
// cleared adds up to the statement's ending balance, and from then on
// its lines can't change. Balances are as the statement shows them: money
// in checking, what's owed on the card.
class BankReconciliation extends Model
{
    protected $fillable = ['account_id', 'statement_date', 'starting_balance_cents', 'statement_ending_balance_cents', 'statement_rows', 'completed_at', 'created_by', 'completed_by'];

    protected $casts = [
        'statement_date' => 'date',
        'starting_balance_cents' => 'integer',
        'statement_ending_balance_cents' => 'integer',
        'statement_rows' => 'array',
        'completed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    // "Capital One Card, Sep 30, 2026".
    public function label(): string
    {
        return "{$this->account->name}, {$this->statement_date->format('M j, Y')}";
    }
}
