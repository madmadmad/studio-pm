<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
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
        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice->loadForDetail(),
            ...Invoice::detailContext(),
        ]);
    }

    // Authenticated, by invoice ID -- for uploading to accounts receivable
    // systems. Clients download the same PDF by token from /i/{token}/pdf.
    public function pdf(Invoice $invoice): HttpResponse
    {
        $invoice->load(['items', 'company', 'contact', 'project', 'payments']);

        $pdf = InvoicePdfRenderer::render($invoice);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
