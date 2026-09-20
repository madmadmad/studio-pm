<?php

namespace App\Listeners;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

// Cashier ships its own webhook route + signature verification (POST
// /stripe/webhook, secret read from STRIPE_WEBHOOK_SECRET) -- this just
// hooks into the event it fires for every verified webhook, rather than
// needing a custom controller/route for the event types we care about.
class MarkInvoicePaidFromStripeWebhook
{
    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return;
        }

        $session = $event->payload['data']['object'] ?? [];

        // ACH is a delayed payment method: its checkout.session.completed
        // fires the moment the client submits their bank details, while the
        // debit itself is still pending (payment_status stays 'unpaid' for
        // days). Only treat .completed as a real payment when Stripe already
        // says it's paid (true for card); the async event is what confirms
        // an ACH debit actually cleared.
        if ($type === 'checkout.session.completed' && ($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $invoice = Invoice::where('stripe_checkout_session_id', $session['id'] ?? null)->first();

        if (! $invoice || $invoice->status === 'paid') {
            return;
        }

        $metadata = $session['metadata'] ?? [];
        $method = $metadata['method'] ?? 'card';
        $baseAmount = (float) ($metadata['base_amount'] ?? $invoice->subtotal());
        $surchargeAmount = (float) ($metadata['surcharge_amount'] ?? 0);

        // The invoice could have been edited (or its items rebilled) between
        // checkout and webhook -- still record what Stripe actually
        // collected rather than silently dropping the payment, but flag the
        // mismatch since it means the client didn't pay the current total.
        if (abs($baseAmount - $invoice->subtotal()) > 0.01) {
            Log::warning("Stripe checkout base_amount ({$baseAmount}) doesn't match invoice #{$invoice->invoice_number} subtotal ({$invoice->subtotal()}) -- recording the payment as received anyway.");
        }

        $invoice->recordPayment($method, $baseAmount, $surchargeAmount, $session['payment_intent'] ?? null);
    }
}
