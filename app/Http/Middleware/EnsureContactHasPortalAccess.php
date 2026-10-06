<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// The Client Hub's counterpart to EnsureUserIsActive: a contact whose
// portal access was revoked (PortalInviteController::destroy) is signed
// out on their next request -- including on a session or remember-me
// cookie from before, which is why this can't just be a sign-in check.
// Only the portal side is signed out: in a staff preview the same session
// holds the staff login too.
class EnsureContactHasPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $contact = Auth::guard('client')->user();

        if ($contact instanceof Contact && ! $contact->hasPortalAccess()) {
            Auth::guard('client')->logout();
            if ($request->hasSession()) {
                $request->session()->forget('portal_preview');
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Your portal access has ended.'], 403)
                : redirect()->route('portal.login')->with('status', 'Your portal access has ended. Contact the studio if you think that’s a mistake.');
        }

        return $next($request);
    }
}
