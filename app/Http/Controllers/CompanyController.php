<?php

namespace App\Http\Controllers;

use App\Enums\PaymentTerms;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index()
    {
        return Company::with('contacts')->latest()->paginate(25);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            // Custom isn't a valid firm-wide-default choice -- it only ever
            // makes sense picked per-invoice.
            'default_payment_terms' => ['nullable', Rule::enum(PaymentTerms::class)->except(PaymentTerms::Custom)],
            'reminders_enabled' => ['nullable', 'boolean'],
        ]);

        // The "use the firm default" option in the dropdown submits an
        // empty string, not the field's absence -- nullable only stops the
        // enum rule from rejecting it, it doesn't turn "" into null itself.
        $data['default_payment_terms'] = ($data['default_payment_terms'] ?? '') ?: null;

        return Company::create($data);
    }

    public function show(Company $company)
    {
        return $company->load('contacts', 'projects', 'invoices', 'proposals');
    }

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:active,inactive'],
            'default_payment_terms' => ['nullable', Rule::enum(PaymentTerms::class)->except(PaymentTerms::Custom)],
            'reminders_enabled' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('default_payment_terms', $data)) {
            $data['default_payment_terms'] = ($data['default_payment_terms'] ?? '') ?: null;
        }

        $company->update($data);

        return $company;
    }

    public function destroy(Company $company)
    {
        $company->delete();

        return response()->noContent();
    }
}
