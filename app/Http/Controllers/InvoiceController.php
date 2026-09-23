<?php

namespace App\Http\Controllers;

use App\Enums\PaymentTerms;
use App\Jobs\SendInvoiceEmailJob;
use App\Mail\InvoiceEmail;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceSend;
use App\Models\TimeEntry;
use App\Services\StripeCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(Company $company)
    {
        return $company->invoices()->with('items')->latest()->get();
    }

    public function store(Request $request, Company $company)
    {
        $data = $this->validateInvoice($request, [
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('company_id', $company->id)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $company->id)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
            'items.*.time_entry_ids' => ['nullable', 'array'],
            'items.*.time_entry_ids.*' => ['integer', 'exists:time_entries,id'],
        ]);

        [$issuedOn, $dueOn, $terms] = $this->resolveDates($data, today(), $company->effectivePaymentTerms());

        $invoice = DB::transaction(function () use ($data, $company, $issuedOn, $dueOn, $terms) {
            $invoice = $company->invoices()->create([
                'project_id' => $data['project_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'status' => 'draft',
                'surcharge' => $data['surcharge'] ?? false,
                'issued_on' => $issuedOn,
                'due_on' => $dueOn,
                'payment_terms' => $terms,
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

        $data = $this->validateInvoice($request, [
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $invoice->company_id)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
        ]);

        [$issuedOn, $dueOn, $terms] = $this->resolveDates($data, $invoice->issued_on, $invoice->payment_terms, $invoice->due_on);

        DB::transaction(function () use ($data, $invoice, $issuedOn, $dueOn, $terms) {
            $invoice->update([
                'contact_id' => $data['contact_id'] ?? null,
                'surcharge' => $data['surcharge'] ?? false,
                'issued_on' => $issuedOn,
                'due_on' => $dueOn,
                'payment_terms' => $terms,
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

    // Backs the Send Invoice modal's footer button for both tabs: Send
    // Email (now or scheduled) and Send via URL. Blocking checks (Step 3)
    // always re-run server-side regardless of what the modal already
    // showed, since the invoice may have changed between page load and
    // submit.
    public function send(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'method' => ['required', 'in:email,link'],
            'subject' => ['required_if:method,email', 'nullable', 'string', 'max:255'],
            'message' => ['required_if:method,email', 'nullable', 'string'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'send_copy_to_self' => ['boolean'],
            'schedule' => ['nullable', 'in:now,later'],
            'scheduled_for' => ['required_if:schedule,later', 'nullable', 'date'],
            'reminders_enabled' => ['nullable', 'boolean'],
            'mark_as_sent' => ['boolean'],
            'update_issue_date' => ['boolean'],
        ]);

        $invoice->loadMissing('contact', 'company.contacts', 'items', 'payments');

        $method = $data['method'];
        $scheduledFor = ($data['schedule'] ?? 'now') === 'later' ? Carbon::parse($data['scheduled_for']) : null;

        $issues = $invoice->sendBlockingIssues();
        if ($method === 'email' && $invoice->contact_email_missing) {
            $issues[] = 'The invoice contact has no email address. Update the contact on the invoice to send by email.';
        }
        abort_if($issues !== [], 422, implode(' ', $issues));

        if ($invoice->needs_issue_date_update && ($data['update_issue_date'] ?? false)) {
            $newIssuedOn = $scheduledFor ? $scheduledFor->copy()->timezone('America/New_York')->startOfDay() : today();
            $invoice->update([
                'issued_on' => $newIssuedOn,
                'due_on' => $invoice->payment_terms->dueDateFrom($newIssuedOn) ?? $invoice->due_on,
            ]);
        }

        if (array_key_exists('reminders_enabled', $data)) {
            $invoice->update(['reminders_enabled' => $data['reminders_enabled']]);
        }

        return $method === 'link'
            ? $this->sendViaLink($request, $invoice, $data)
            : $this->sendViaEmail($request, $invoice, $data, $scheduledFor);
    }

    private function sendViaLink(Request $request, Invoice $invoice, array $data)
    {
        DB::transaction(function () use ($request, $invoice, $data) {
            $invoice->invoiceSends()->create([
                'type' => InvoiceSend::TYPE_LINK,
                'status' => InvoiceSend::STATUS_SENT,
                'sent_at' => now(),
                'sent_by_user_id' => $request->user()->id,
                'recipients' => [],
            ]);

            if ($data['mark_as_sent'] ?? true) {
                $invoice->update([
                    'status' => $invoice->status === 'draft' ? 'sent' : $invoice->status,
                    'sent_at' => $invoice->sent_at ?? now(),
                ]);
            }
        });

        return $this->freshWithSendAppends($invoice);
    }

    private function sendViaEmail(Request $request, Invoice $invoice, array $data, ?Carbon $scheduledFor)
    {
        $cc = collect($data['cc'] ?? [])->filter()->values()->all();
        if ($data['send_copy_to_self'] ?? false) {
            $cc[] = $request->user()->email;
        }

        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL,
            'status' => $scheduledFor ? InvoiceSend::STATUS_SCHEDULED : InvoiceSend::STATUS_QUEUED,
            'scheduled_for' => $scheduledFor,
            'sent_by_user_id' => $request->user()->id,
            'recipients' => [$invoice->billingContact()->email],
            'cc' => $cc,
            'subject' => $data['subject'],
            'message' => $data['message'],
        ]);

        if (! $scheduledFor) {
            SendInvoiceEmailJob::dispatch($invoiceSend->id);
        }

        return $this->freshWithSendAppends($invoice);
    }

    private function freshWithSendAppends(Invoice $invoice): Invoice
    {
        return $invoice->fresh(['items', 'invoiceSends', 'company.contacts', 'contact', 'payments'])
            ->append(['send_blocking_issues', 'contact_email_missing', 'needs_issue_date_update', 'remaining_balance', 'effective_reminders_enabled', 'public_url']);
    }

    // Renders the exact HTML a client would receive for the modal's "Show
    // Email Preview" link -- the subject/message come straight from the
    // modal's current (unsaved) form state, not anything stored yet.
    public function emailPreview(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        $invoice->loadMissing('company', 'contact', 'items', 'payments');

        $mail = new InvoiceEmail($invoice, $data['subject'], $data['message']);

        return ['html' => $mail->render()];
    }

    public function regenerateToken(Invoice $invoice)
    {
        $invoice->regenerateToken();

        return $invoice->fresh();
    }

    // Pre-empts one specific upcoming reminder rule for this invoice by
    // creating its (invoice_id, reminder_rule) row already cancelled -- the
    // same unique index that keeps a rule from firing twice then makes the
    // daily reminders command's own create() call collide and skip it when
    // that day arrives, with no separate "skip list" needed.
    public function skipReminder(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['rule' => ['required', 'string']]);

        $invoiceSend = $invoice->invoiceSends()->firstOrCreate(
            ['reminder_rule' => $data['rule'], 'type' => InvoiceSend::TYPE_REMINDER],
            ['status' => InvoiceSend::STATUS_CANCELLED, 'failure_reason' => 'Skipped by a team member.']
        );

        if ($invoiceSend->wasRecentlyCreated === false && $invoiceSend->status !== InvoiceSend::STATUS_CANCELLED) {
            abort(422, 'This reminder has already gone out and can no longer be skipped.');
        }

        return $invoiceSend;
    }

    public function cancelSend(Invoice $invoice, InvoiceSend $invoiceSend)
    {
        abort_unless($invoiceSend->invoice_id === $invoice->id, 404);
        abort_unless($invoiceSend->status === InvoiceSend::STATUS_SCHEDULED, 422, 'Only a pending scheduled send can be cancelled.');

        $invoiceSend->update(['status' => InvoiceSend::STATUS_CANCELLED, 'failure_reason' => 'Cancelled by a team member.']);

        return $invoiceSend;
    }

    public function rescheduleSend(Request $request, Invoice $invoice, InvoiceSend $invoiceSend)
    {
        abort_unless($invoiceSend->invoice_id === $invoice->id, 404);
        abort_unless($invoiceSend->status === InvoiceSend::STATUS_SCHEDULED, 422, 'Only a pending scheduled send can be rescheduled.');

        $data = $request->validate(['scheduled_for' => ['required', 'date']]);

        $invoiceSend->update(['scheduled_for' => Carbon::parse($data['scheduled_for'])]);

        return $invoiceSend;
    }

    public function sendNow(Invoice $invoice, InvoiceSend $invoiceSend)
    {
        abort_unless($invoiceSend->invoice_id === $invoice->id, 404);
        abort_unless($invoiceSend->status === InvoiceSend::STATUS_SCHEDULED, 422, 'Only a pending scheduled send can be sent now.');

        $invoiceSend->update(['status' => InvoiceSend::STATUS_QUEUED, 'scheduled_for' => null]);
        SendInvoiceEmailJob::dispatch($invoiceSend->id);

        return $invoiceSend;
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

    // Shared by store() and update() so the two never disagree on what a
    // date/terms payload means. due_on's relationship to issued_on is
    // checked here regardless of payment_terms, since it should never make
    // sense even for a value that later gets overridden by resolveDates().
    private function validateInvoice(Request $request, array $extraRules): array
    {
        $rules = array_merge([
            'surcharge' => ['boolean'],
            'issued_on' => ['nullable', 'date'],
            'payment_terms' => ['nullable', Rule::enum(PaymentTerms::class)],
            'due_on' => ['nullable', 'date'],
        ], $extraRules);

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if (! $request->filled('due_on')) {
                return;
            }

            $issuedOn = $request->input('issued_on') ?: today()->toDateString();

            if ($request->input('due_on') < $issuedOn) {
                $validator->errors()->add('due_on', 'The due date must be on or after the issue date.');
            }
        });

        return $validator->validate();
    }

    // The one place a due date gets computed from issued_on + payment_terms
    // -- the server always recomputes it for any non-Custom term (ignoring
    // whatever due_on the client sent), and only trusts a client-submitted
    // due_on when the term is genuinely Custom. $fallbackDueOn is only
    // relevant for that Custom case; on create there's nothing to fall back
    // to yet, so it defaults to the issue date itself.
    private function resolveDates(array $data, Carbon|string $fallbackIssuedOn, PaymentTerms $fallbackTerms, Carbon|string|null $fallbackDueOn = null): array
    {
        $issuedOn = isset($data['issued_on']) ? Carbon::parse($data['issued_on']) : Carbon::parse($fallbackIssuedOn);
        $terms = isset($data['payment_terms']) ? PaymentTerms::from($data['payment_terms']) : $fallbackTerms;

        $dueOn = $terms === PaymentTerms::Custom
            ? (isset($data['due_on']) ? Carbon::parse($data['due_on']) : Carbon::parse($fallbackDueOn ?? $issuedOn))
            : $terms->dueDateFrom($issuedOn);

        return [$issuedOn, $dueOn, $terms];
    }
}
