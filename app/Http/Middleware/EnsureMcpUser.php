<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Every call to the MCP server (routes/ai.php), after auth:api: the token's
// staff member must still be active, staff (not the accountant), and set
// up with two-factor when that's required -- checked on each request, not
// just when the app was approved, since Passport skips its consent screen
// for an app someone has approved before. EnsureUserIsActive (global)
// runs before auth:api has found the user, so it can't do this here.
class EnsureMcpUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isActive() && $user->isStaff(), 403, 'This account cannot use the MCP server.');
        abort_if(config('auth.require_two_factor') && ! $user->two_factor_confirmed_at, 403, 'Set up two-factor sign-in first.');

        return $next($request);
    }
}
