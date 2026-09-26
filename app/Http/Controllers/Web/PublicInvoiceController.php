<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\StudioProfile;
use App\Services\InvoicePdfRenderer;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PublicInvoiceController extends Controller
{
    public function show(string $token): Response
    {
        $invoice = Invoice::with(['company', 'project', 'contact', 'items', 'payments'])
            ->where('public_token', $token)
            ->firstOrFail();

        return Inertia::render('Public/InvoiceShow', [
            'invoice' => $invoice,
            'studio' => StudioProfile::current(),
        ]);
    }

    // The download icon on the public page: the same PDF the studio
    // attaches to invoice emails, gated by the same token as the page.
    public function pdf(string $token): HttpResponse
    {
        $invoice = Invoice::with(['company', 'project', 'contact', 'items', 'payments'])
            ->where('public_token', $token)
            ->firstOrFail();

        return InvoicePdfRenderer::render($invoice)->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
