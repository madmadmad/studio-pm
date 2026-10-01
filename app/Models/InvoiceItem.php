<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'service_id', 'description', 'details', 'amount', 'taxable', 'position'];

    protected $casts = [
        'taxable' => 'boolean',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    // The expense this line bills, when it was added from one.
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }
}
