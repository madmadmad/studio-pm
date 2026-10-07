<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Throwable;

// Stripe's processing fee on a payment, read from the charge's balance
// transaction when the webhook records it -- so the ledger books the fee
// on the day of the payment, not the day of the payout. One read-only
// call; nothing else of Stripe's is fetched or stored.
class StripeFees
{
    // The fee in dollars, or null when it can't be had (no keys, or Stripe
    // didn't answer): the payment then posts gross, and entering the
    // payout picks the fee up.
    public function forPaymentIntent(?string $paymentIntentId): ?float
    {
        if (! $paymentIntentId || ! config('services.stripe.secret')) {
            return null;
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $intent = PaymentIntent::retrieve(['id' => $paymentIntentId, 'expand' => ['latest_charge.balance_transaction']]);
            $fee = $intent->latest_charge?->balance_transaction?->fee;

            return $fee === null ? null : $fee / 100;
        } catch (Throwable $e) {
            Log::warning("Couldn't read Stripe's fee for {$paymentIntentId}: {$e->getMessage()}");

            return null;
        }
    }
}
