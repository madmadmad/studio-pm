<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Gate for a route group by permission (config/permissions.php) --
     * any one of those listed lets the request through. Per-record rules
     * ("your assigned projects only") live in the Policies.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        abort_unless($user && collect($permissions)->contains(fn (string $p) => $user->hasPermission($p)), 403);

        return $next($request);
    }
}
