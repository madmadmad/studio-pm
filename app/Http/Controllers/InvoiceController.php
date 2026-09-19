<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\Transaction;
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
                'due_on' => $data['due_on'] ?? now()->addDays(14),
            ]);

            foreach ($data['items'] as $item) {
                $invoiceItem = $invoice->items()->create([
                    'description' => $item['description'],
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
            $invoice->items()->delete();

            $invoice->items()->createMany($data['items']);
        });

        return $invoice->fresh()->load('items');
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
            'project_id' => $invoice->project_id,
        ]);

        return $invoice->load('items', 'payments');
    }
}
