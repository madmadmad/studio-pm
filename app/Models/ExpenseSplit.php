<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A client's share of a shared expense (their part of the month's hosting
// bill), for hosting profitability.
class ExpenseSplit extends Model
{
    protected $fillable = ['expense_id', 'company_id', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
