<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use Inertia\Inertia;
use Inertia\Response;

class InvoicePageController extends Controller
{
    public function index(): Response
    {
        $invoices = Invoice::with(['items', 'company'])->latest()->get();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items.service', 'items.timeEntries', 'company', 'payments']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }
}
