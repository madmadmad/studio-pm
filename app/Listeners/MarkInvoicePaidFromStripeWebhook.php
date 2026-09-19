<?php

namespace App\Listeners;

use App\Models\Invoice;
use Laravel\Cashier\Events\WebhookReceived;

// Cashier ships its own webhook route + signature verification (POST
// /stripe/webhook, secret read from STRIPE_WEBHOOK_SECRET) -- this just
// hooks into the event it fires for every verified webhook, rather than
// needing a custom controller/route for the one event type we care about.
class MarkInvoicePaidFromStripeWebhook
{
    public function handle(WebhookReceived $event): void
    {
        if ($event->payload['type'] !== 'checkout.session.completed') {
            return;
        }

        $sessionId = $event->payload['data']['object']['id'] ?? null;

        $invoice = Invoice::where('stripe_checkout_session_id', $sessionId)->first();

        if ($invoice && $invoice->status !== 'paid') {
            $invoice->recordPayment();
        }
    }
}
