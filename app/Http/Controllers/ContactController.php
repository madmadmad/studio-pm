<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Company $company)
    {
        return $company->contacts;
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'role' => ['nullable', 'string'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_billing' => ['sometimes', 'boolean'],
        ]);

        $contact = $company->contacts()->create($data);
        $this->enforceSingleFlags($contact);

        // Fresh, so flags left out of the request come back with their
        // column defaults -- the client page shows the response as-is.
        return $contact->refresh();
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'role' => ['nullable', 'string'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_billing' => ['sometimes', 'boolean'],
        ]);

        $contact->update($data);
        $this->enforceSingleFlags($contact);

        return $contact->refresh();
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return response()->noContent();
    }

    // Only one contact per company can be primary -- setting it here clears
    // it from every other contact at the same company. Billing isn't
    // exclusive: a client can have several billing contacts.
    protected function enforceSingleFlags(Contact $contact): void
    {
        if ($contact->is_primary) {
            Contact::where('company_id', $contact->company_id)
                ->where('id', '!=', $contact->id)
                ->update(['is_primary' => false]);
        }
    }
}
