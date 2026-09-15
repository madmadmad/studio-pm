<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    // Wire this up once Cashier is installed -- Cashier ships its own
    // webhook controller (Laravel\Cashier\Http\Controllers\WebhookController)
    // that you extend to react to `checkout.session.completed` and call
    // InvoiceController::markPaid() on the matching invoice.
    public function handle(Request $request)
    {
        return response()->json(['status' => 'not yet wired to Cashier']);
    }
}
