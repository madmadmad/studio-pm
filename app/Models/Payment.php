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

    // Card payments come through Stripe (and land in Stripe Clearing until
    // a payout). ACH, checks and the rest are recorded by hand and go
    // straight to the bank -- unless Stripe took them (an ACH checkout
    // carries its payment intent).
    public function viaStripe(): bool
    {
        return $this->method === 'card' || $this->stripe_payment_intent_id !== null;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
