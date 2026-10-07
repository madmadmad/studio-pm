<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['invoice_id', 'method', 'amount', 'surcharge_amount', 'late_fee', 'stripe_fee', 'stripe_payment_intent_id', 'paid_at'];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    // Payments Stripe took (they carry its payment intent) land in Stripe
    // Clearing until a payout. Everything else went straight to the bank:
    // ACH and checks recorded by hand, and card payments imported from
    // Bonsai, whose payouts we don't have.
    public function viaStripe(): bool
    {
        return $this->stripe_payment_intent_id !== null;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
