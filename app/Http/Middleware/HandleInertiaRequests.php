<?php

namespace App\Http\Middleware;

use App\Models\Contact;
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
            ];
        }

        return $user;
    }
}
