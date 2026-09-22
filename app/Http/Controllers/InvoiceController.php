<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Notifications\InvoiceSent;
use App\Services\StripeCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(Company $company)
    {
        return $company->invoices()->with('items')->latest()->get();
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('company_id', $company->id)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $company->id)],
            'surcharge' => ['boolean'],
            'due_on' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
            'items.*.time_entry_ids' => ['nullable', 'array'],
            'items.*.time_entry_ids.*' => ['integer', 'exists:time_entries,id'],
        ]);

        $invoice = DB::transaction(function () use ($data, $company) {
            $invoice = $company->invoices()->create([
                'project_id' => $data['project_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'status' => 'draft',
                'surcharge' => $data['surcharge'] ?? false,
                'issued_on' => now(),
                'due_on' => $data['due_on'] ?? now()->addDays(30), // Net 30 by default
            ]);

            foreach ($data['items'] as $item) {
                $invoiceItem = $invoice->items()->create([
                    'description' => $item['description'],
                    'details' => $item['details'] ?? null,
                    'amount' => $item['amount'],
                    'service_id' => $item['service_id'] ?? null,
                ]);

                if (! empty($item['time_entry_ids'])) {
                    TimeEntry::whereIn('id', $item['time_entry_ids'])
                        ->where('company_id', $company->id)
                        ->update([
                            'billed' => true,
                            'invoice_item_id' => $invoiceItem->id,
                        ]);
                }
            }

            return $invoice;
        });

        return $invoice->load('items');
    }

    public function update(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->status === 'draft', 422, 'Only draft invoices can be edited.');

        $data = $request->validate([
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $invoice->company_id)],
            'surcharge' => ['boolean'],
            'due_on' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
        ]);

        DB::transaction(function () use ($data, $invoice) {
            $invoice->update([
                'contact_id' => $data['contact_id'] ?? null,
                'surcharge' => $data['surcharge'] ?? false,
                'due_on' => $data['due_on'] ?? $invoice->due_on,
            ]);

            // Replacing items wholesale (simplest, matches how proposal items
            // are edited) would otherwise leave any time entries billed to
            // the old items stuck "billed" with nothing to point at -- free
            // them up so those hours can be invoiced again later.
            $oldItemIds = $invoice->items()->pluck('id');
            TimeEntry::whereIn('invoice_item_id', $oldItemIds)->update(['billed' => false, 'invoice_item_id' => null]);
            Expense::whereIn('invoice_item_id', $oldItemIds)->update([
                'invoice_id' => null, 'invoice_item_id' => null, 'billing_status' => 'unbilled',
            ]);
            $invoice->items()->delete();

            $invoice->items()->createMany($data['items']);
        });

        return $invoice->fresh()->load('items');
    }

    public function send(Invoice $invoice)
    {
        abort_unless($invoice->status === 'draft', 422, 'This invoice has already been sent.');

        $invoice->loadMissing('contact', 'company.contacts', 'items', 'project', 'payments');

        // The invoice's own contact_id is only set when someone picked a
        // specific person to bill; "no specific contact" (the default) is a
        // normal, common state that still needs a real recipient. Fall back
        // to the company's billing contact, then its primary contact --
        // same precedence the rest of the app uses when defaulting a
        // company-level contact (e.g. Projects/Proposals pick the primary
        // contact when nothing more specific is chosen).
        $recipient = $invoice->contact
            ?? $invoice->company->contacts->firstWhere('is_billing', true)
            ?? $invoice->company->contacts->firstWhere('is_primary', true);

        abort_if(! $recipient?->email, 422, 'This invoice has no billing contact with an email address.');

        DB::transaction(function () use ($invoice, $recipient) {
            $recipient->notify(new InvoiceSent($invoice));

            $invoice->update(['status' => 'sent']);
        });

        return $invoice;
    }

    // Manual, manager-only -- for payments that never touch Stripe (check,
    // cash, or anything else recorded by hand). Never applies a surcharge,
    // regardless of the invoice's own card-surcharge flag: that flag only
    // ever governs whether the client is offered a card option, and a card
    // fee that was never actually collected has no business in the books.
    public function markPaid(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'method' => ['required', 'in:check,other'],
        ]);

        $invoice->recordPayment($data['method'], $invoice->subtotal());

        return $invoice->load('items', 'payments');
    }

    public function destroy(Invoice $invoice)
    {
        abort_if($invoice->status === 'paid', 422, 'Paid invoices cannot be deleted.');

        DB::transaction(function () use ($invoice) {
            // Same reasoning as update(): don't leave time entries stuck
            // "billed" with nothing to point at once the invoice is gone.
            $itemIds = $invoice->items()->pluck('id');
            TimeEntry::whereIn('invoice_item_id', $itemIds)->update(['billed' => false, 'invoice_item_id' => null]);
            Expense::whereIn('invoice_item_id', $itemIds)->update([
                'invoice_id' => null, 'invoice_item_id' => null, 'billing_status' => 'unbilled',
            ]);

            $invoice->delete();
        });

        return response()->noContent();
    }

    // Public, unauthenticated -- the "Pay by card" / "Pay by ACH" buttons on
    // the client-facing invoice page. Creates a Stripe Checkout Session and
    // hands back its URL for the browser to redirect to;
    // MarkInvoicePaidFromStripeWebhook is what actually marks the invoice
    // paid once Stripe confirms it.
    public function checkout(Request $request, string $token, StripeCheckoutService $checkout)
    {
        $data = $request->validate([
            'method' => ['required', 'in:card,ach'],
        ]);

        $invoice = Invoice::with('items')->where('public_token', $token)->firstOrFail();

        abort_unless($invoice->status === 'sent', 422, 'This invoice is not ready to be paid.');

        if ($data['method'] === 'card') {
            abort_unless($invoice->allowsCardPayment(), 422, 'Card payment is not available for this invoice.');
            $session = $checkout->createCardSessionFor($invoice);
        } else {
            $session = $checkout->createAchSessionFor($invoice);
        }

        $invoice->update(['stripe_checkout_session_id' => $session->id]);

        return ['url' => $session->url];
    }
}
