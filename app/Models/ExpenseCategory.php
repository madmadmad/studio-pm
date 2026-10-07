<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// What an expense was for, as picked when recording it (named like our
// Bonsai tags). Each posts to a ledger account; billable_account_id is the
// one used instead when the expense is billed to a client (Advertising:
// our own marketing, or client media spend).
class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'color', 'account_id', 'billable_account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function billableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'billable_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
