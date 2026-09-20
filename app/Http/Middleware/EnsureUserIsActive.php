<?php

namespace App\Http\Middleware;

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
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
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
