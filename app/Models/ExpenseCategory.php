<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// What an expense was for, as picked when recording it (named like our
// Bonsai tags). Each posts to a ledger account; billable_account_id is the
// one used instead when the expense is billed to a client (Advertising:
// our own marketing, or client media spend). revenue_account_id and
// taxable_when_billed shape the invoice line when it's rebilled.
class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'color', 'account_id', 'billable_account_id', 'revenue_account_id', 'taxable_when_billed'];

    protected $casts = [
        'taxable_when_billed' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function billableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'billable_account_id');
    }

    // Where the income posts when its expenses are rebilled to a client
    // (Hosting → Hosting). Null: the line's service or invoice decides.
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
