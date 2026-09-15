<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Company $company)
    {
        return $company->invoices()->with('items')->latest()->get();
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'surcharge' => ['boolean'],
            'due_on' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
        ]);

        $invoice = $company->invoices()->create([
            'status' => 'draft',
            'surcharge' => $data['surcharge'] ?? false,
            'issued_on' => now(),
            'due_on' => $data['due_on'] ?? now()->addDays(14),
        ]);

        $invoice->items()->createMany($data['items']);

        return $invoice->load('items');
    }

    public function send(Invoice $invoice)
    {
        $invoice->update(['status' => 'sent']);

        // Create the Stripe Checkout Session here via StripeCheckoutService
        // and store its ID on $invoice->stripe_checkout_session_id.
        // That's also where the surcharge toggle actually takes effect.

        return $invoice->load('items');
    }

    public function markPaid(Invoice $invoice)
    {
        $invoice->update(['status' => 'paid']);

        $invoice->payments()->create([
            'amount' => $invoice->subtotal(),
            'surcharge_amount' => $invoice->surchargeAmount(),
            'paid_at' => now(),
        ]);

        // Feeds the simple bookkeeping ledger automatically -- no manual entry needed.
        Transaction::create([
            'type' => 'income',
            'amount' => $invoice->total(),
            'category' => 'client invoice',
            'occurred_on' => now(),
            'invoice_id' => $invoice->id,
        ]);

        return $invoice->load('items', 'payments');
    }
}
