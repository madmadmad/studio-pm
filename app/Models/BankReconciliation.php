<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A bank or card statement matched against the ledger: the lines it
// covers point back at it. Schema only for now -- no reconciliation
// screen yet.
class BankReconciliation extends Model
{
    protected $fillable = ['account_id', 'statement_date', 'statement_ending_balance_cents', 'completed_at'];

    protected $casts = [
        'statement_date' => 'date',
        'statement_ending_balance_cents' => 'integer',
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
}
