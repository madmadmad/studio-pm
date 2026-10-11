<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Two-factor is required of all staff (config auth.require_two_factor):
// until someone has an authenticator set up, every page sends them to set
// it up, and anything else is refused. The accountant, who signs in by an
// emailed link, isn't asked. Sits on the staff app's web and API groups.
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('auth.require_two_factor')
            || ! $user instanceof User
            || ! $user->isStaff()
            || $user->two_factor_confirmed_at !== null
            || $request->routeIs('security.setup')) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect()->route('security.setup');
        }

        abort(403, 'Set up two-factor sign-in first.');
    }
}
