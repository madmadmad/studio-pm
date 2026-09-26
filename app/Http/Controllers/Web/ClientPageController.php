<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

class ClientPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Clients/Index', [
            'companies' => Company::with('contacts')->orderBy('name')->get(),
        ]);
    }

    public function show(Company $company): Response
    {
        $company->load([
            'contacts',
            // Same order as the Projects page.
            'projects' => fn ($query) => $query->orderBy('name'),
            'projects.tasks',
            // For the New invoice drawer: each project's budget, proposals
            // to copy line items from, and invoices so far.
            'projects.proposals.items',
            'projects.invoices.items',
            'invoices' => fn ($query) => $query->orderByDesc('issued_on')->orderByDesc('invoice_number'),
            'invoices.items',
            'invoices.project',
            'proposals' => fn ($query) => $query->latest(),
            'proposals.project',
        ]);

        return Inertia::render('Clients/Show', [
            'company' => $company,
            // For the "Firm default (Net 30)" option in the terms dropdown.
            'firmPaymentTerms' => config('invoicing.default_payment_terms'),
            // Line-item presets for the New proposal drawer.
            'services' => Service::orderBy('name')->get(),
        ]);
    }
}
