<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['invoice_id', 'method', 'amount', 'surcharge_amount', 'stripe_fee', 'stripe_payment_intent_id', 'paid_at'];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    // Card and ACH come through Stripe (and land in Stripe Clearing until
    // a payout); a check or anything else goes straight to the bank.
    public function viaStripe(): bool
    {
        return in_array($this->method, ['card', 'ach'], true);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
