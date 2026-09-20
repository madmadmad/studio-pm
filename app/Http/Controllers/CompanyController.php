<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

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
        ]);

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
        ]);

        $company->update($data);

        return $company;
    }

    public function destroy(Company $company)
    {
        $company->delete();

        return response()->noContent();
    }
}
