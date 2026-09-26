<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
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
            'projects.tasks',
            'invoices.items',
            'proposals',
        ]);

        return Inertia::render('Clients/Show', [
            'company' => $company,
            // For the "Firm default (Net 30)" option in the terms dropdown.
            'firmPaymentTerms' => config('invoicing.default_payment_terms'),
        ]);
    }
}
