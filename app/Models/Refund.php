<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Money given back to a client on an invoice: recorded once it's gone
// back (the app doesn't send it). Taken back off the revenue it was booked
// to (App\Services\Posting\RefundPoster); the invoice stays paid.
class Refund extends Model
{
    protected $fillable = ['invoice_id', 'method', 'amount', 'surcharge_amount', 'reason', 'refunded_at', 'created_by'];

    protected $casts = [
        'refunded_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // A card refund comes out of what Stripe holds; anything else out of
    // checking.
    public function viaStripe(): bool
    {
        return $this->method === 'card';
    }
}
