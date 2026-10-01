<?php

namespace App\Http\Controllers;

use App\Models\StudioProfile;
use Illuminate\Http\Request;

class StudioProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'payment_instructions' => ['nullable', 'string'],
            // New proposals start with it; blank for none.
            'proposal_disclaimer' => ['nullable', 'string'],
            // Charged on invoices with "Charge Tax" on; no rate, no tax.
            'sales_tax_name' => ['nullable', 'string', 'max:255'],
            'sales_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $profile = StudioProfile::current();
        $profile->update($data);

        return $profile;
    }
}
