<?php

namespace App\Services;

use App\Models\Invoice;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripeCheckoutService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Card payment: restricted to card, with the 3% fee broken out as its
     * own line item (never folded silently into one lump amount, so the
     * client sees exactly what they're being charged and why) and never
     * written anywhere on the Invoice itself. base_amount/surcharge_amount
     * go into metadata so the webhook can reconcile without recomputing
     * anything from an invoice that may have changed since checkout.
     */
    public function createCardSessionFor(Invoice $invoice): Session
    {
        $baseAmount = $invoice->subtotal();
        $surchargeAmount = $invoice->cardSurchargeAmount();

        return Session::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [
                ...$this->itemLineItems($invoice),
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => ['name' => 'Card processing fee (3%)'],
                        'unit_amount' => $this->toCents($surchargeAmount),
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'method' => 'card',
                'base_amount' => number_format($baseAmount, 2, '.', ''),
                'surcharge_amount' => number_format($surchargeAmount, 2, '.', ''),
            ],
            'success_url' => url('/i/'.$invoice->public_token.'?paid=1'),
            'cancel_url' => url('/i/'.$invoice->public_token),
        ]);
    }

    /**
     * ACH bank transfer: base invoice amount only, no fee. ACH settles
     * asynchronously (days, not seconds), which is why the webhook listener
     * waits for checkout.session.async_payment_succeeded for this method
     * rather than trusting checkout.session.completed alone.
     */
    public function createAchSessionFor(Invoice $invoice): Session
    {
        $baseAmount = $invoice->subtotal();

        return Session::create([
            'mode' => 'payment',
            'payment_method_types' => ['us_bank_account'],
            'line_items' => $this->itemLineItems($invoice),
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'method' => 'ach',
                'base_amount' => number_format($baseAmount, 2, '.', ''),
                'surcharge_amount' => '0.00',
            ],
            'success_url' => url('/i/'.$invoice->public_token.'?paid=1'),
            'cancel_url' => url('/i/'.$invoice->public_token),
        ]);
    }

    protected function itemLineItems(Invoice $invoice): array
    {
        return $invoice->items->map(fn ($item) => [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => $item->description],
                'unit_amount' => $this->toCents($item->amount),
            ],
            'quantity' => 1,
        ])->all();
    }

    protected function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
