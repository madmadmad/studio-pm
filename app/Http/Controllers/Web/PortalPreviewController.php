<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// A manager's read-only look at a client's portal (the icon on the Clients
// list): signs this browser into the portal as one of the client's portal
// contacts -- the primary, else a billing contact, else any -- alongside
// the staff session, not instead of it. PortalPreviewReadOnly blocks every
// change while the preview is on; exiting signs only the portal side out.
class PortalPreviewController extends Controller
{
    public function start(Request $request, Company $company)
    {
        $contact = $company->contacts()
            ->whereNotNull('portal_invited_at')
            ->orderByDesc('is_primary')
            ->orderByDesc('is_billing')
            ->orderBy('name')
            ->first();

        if (! $contact) {
            return redirect()->route('clients.index')->with('error', "No one at {$company->name} has portal access yet.");
        }

        Auth::guard('client')->login($contact);
        $request->session()->put('portal_preview', [
            'contact_id' => $contact->id,
            'company_id' => $company->id,
        ]);

        return redirect('/portal');
    }

    // Back to the client's page in the staff app, still signed in there.
    public function exit(Request $request)
    {
        $companyId = $request->session()->pull('portal_preview')['company_id'] ?? null;
        Auth::guard('client')->logout();

        return redirect($companyId ? "/clients/{$companyId}" : '/clients');
    }
}
