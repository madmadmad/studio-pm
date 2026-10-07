<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    protected $fillable = ['name', 'description', 'default_rate', 'unit', 'billable', 'revenue_account_id'];

    // Where income billed for this service posts (Ad management → Ad
    // Management). Null: the invoice category's, else Design & Development.
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    // Whether time logged against this service is billable (see
    // TimeEntryController, which copies it onto the entry).
    protected $casts = [
        'billable' => 'boolean',
    ];
}
