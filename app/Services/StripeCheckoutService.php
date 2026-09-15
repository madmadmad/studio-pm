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

    public function createSessionFor(Invoice $invoice): Session
    {
        return Session::create([
            'mode' => 'payment',
            'line_items' => $invoice->items->map(fn ($item) => [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $item->description],
                    'unit_amount' => (int) round($item->amount * 100),
                ],
                'quantity' => 1,
            ])->all(),
            // This is the whole surcharge feature -- Stripe calculates and
            // discloses the card fee automatically when this is enabled.
            // Note: as of now this parameter is on a preview Stripe API
            // version, so check your dashboard/API version before relying on it.
            'automatic_surcharge' => ['enabled' => $invoice->surcharge],
            'success_url' => url('/invoices/' . $invoice->id . '?paid=1'),
            'cancel_url' => url('/invoices/' . $invoice->id),
        ]);
    }
}
