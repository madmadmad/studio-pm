<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\StudioProfile;
use App\Services\InvoicePdfRenderer;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoicePageController extends Controller
{
    public function index(): Response
    {
        $invoices = Invoice::with(['items', 'company'])
            ->withExists(['invoiceSends as has_pending_scheduled_send' => fn ($query) => $query->where('type', 'email')->where('status', 'scheduled')])
            ->latest()->get();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            // default_payment_terms has to be selected explicitly -- it
            // backs the effective_payment_terms appended accessor the
            // new-invoice form's client picker relies on.
            'companies' => Company::with('contacts')->orderBy('name')->get(['id', 'name', 'default_payment_terms']),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load([
            'items.service', 'items.timeEntries', 'company.contacts', 'contact', 'project', 'payments',
            'invoiceSends' => fn ($query) => $query->with('sentBy:id,name')->latest('id'),
        ]);
        $invoice->append(['send_blocking_issues', 'contact_email_missing', 'needs_issue_date_update', 'remaining_balance', 'effective_reminders_enabled', 'public_url']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
            'studio' => StudioProfile::current(),
            'invoicingDefaults' => [
                'emailTemplate' => config('invoicing.email_template'),
                'emailSubjectTemplate' => config('invoicing.email_subject_template'),
            ],
        ]);
    }

    // Authenticated only -- for uploading to accounts receivable systems,
    // not the client-facing document (that's the public /i/{token} page).
    public function pdf(Invoice $invoice): HttpResponse
    {
        $invoice->load(['items', 'company', 'contact', 'project', 'payments']);

        $pdf = InvoicePdfRenderer::render($invoice);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
