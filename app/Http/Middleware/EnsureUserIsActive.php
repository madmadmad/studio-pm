<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * A deactivated staff member keeps their DB row (their time entries,
     * invoices, etc. still need the FK) but can no longer authenticate --
     * including on a session/remember-me cookie from before they were
     * deactivated, which is why this can't just be a login-time check.
     *
     * This runs globally, including on Client Hub routes where the
     * authenticated principal is a Contact, not a User -- guard by type
     * rather than by guard name. (An explicit $request->user('web') looks
     * like the more obvious guard here, but it resolves the raw session
     * guard directly rather than through whichever guard actually
     * authenticated the request, e.g. 'sanctum', and was observed returning
     * stale state across multiple actingAs() calls in one test; checking
     * the default-guard user's type is both simpler and correct.)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();

            // Not every guarded request carries a session (e.g. a token-based
            // API call) -- only tear one down if there's actually one here.
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
