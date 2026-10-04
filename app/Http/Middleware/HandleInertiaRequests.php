<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use App\Models\InvoiceCategory;
use App\Models\StudioProfile;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $this->sharedUser($request),
            ],
            // The studio's logo (Settings), for light and dark backgrounds --
            // every page, signed in or not (sign-in screens, public links).
            'branding' => fn () => [
                'name' => StudioProfile::brandName(),
                'logo' => StudioProfile::current()->logoUrl(),
                'logo_dark' => StudioProfile::current()->logoDarkUrl(),
            ],
            // What a new proposal starts from (config/proposals.php). Staff
            // only -- the portal never creates proposals.
            'proposalDefaults' => fn () => $request->user() instanceof Contact ? null : [
                'disclaimer' => StudioProfile::current()->proposal_disclaimer,
            ],
            // A manager's read-only look at this portal (PortalPreviewController):
            // whom it's showing, for the banner across the top.
            'portalPreview' => fn () => $request->session()->has('portal_preview') && $request->user() instanceof Contact
                ? ['contact' => $request->user()->name, 'company' => $request->user()->company?->name]
                : null,
            // What an invoice can be categorised as, beyond project work
            // (Settings). Staff only.
            'invoiceCategories' => fn () => $request->user() instanceof Contact ? null : InvoiceCategory::orderBy('name')->get(['id', 'name']),
            // The sales tax an invoice takes when it's switched on (Settings).
            // Staff only.
            'salesTax' => fn () => $request->user() instanceof Contact ? null : StudioProfile::current()->salesTax(),
        ];
    }

    // A Client Hub contact gets only what the portal screens use (the
    // header, message authorship). Sharing the whole model would send
    // whatever relations a page happened to load -- e.g. the company with
    // its payment terms and reminder settings -- to the client's browser.
    protected function sharedUser(Request $request): mixed
    {
        $user = $request->user();

        if ($user instanceof Contact) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                // Whether the sidebar shows Invoices.
                'can_view_invoices' => $user->canViewInvoices(),
                'theme' => $user->theme,
            ];
        }

        // Staff: with what they're allowed to do (config/permissions.php),
        // for the sidebar, tabs and buttons -- the server enforces it too.
        return $user ? [...$user->toArray(), 'permissions' => $user->effectivePermissions()] : null;
    }
}
