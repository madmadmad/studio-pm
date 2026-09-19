<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\StudioProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoicePageController extends Controller
{
    public function index(): Response
    {
        $invoices = Invoice::with(['items', 'company'])->latest()->get();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'companies' => Company::with('contacts')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items.service', 'items.timeEntries', 'company.contacts', 'contact', 'project', 'payments']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    // Authenticated only -- for uploading to accounts receivable systems,
    // not the client-facing document (that's the public /i/{token} page).
    public function pdf(Invoice $invoice): HttpResponse
    {
        $invoice->load(['items', 'company', 'contact', 'project', 'payments']);

        $pdf = Pdf::loadView('pdfs.invoice', [
            'invoice' => $invoice,
            'studio' => StudioProfile::current(),
        ]);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
