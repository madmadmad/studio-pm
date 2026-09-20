<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Blanket gate for whole route groups that only one role should ever
     * reach at all (firm financials, the client directory, user admin).
     * Per-record rules (e.g. "your assigned projects only") live in
     * Policies instead, since those need to inspect the specific model.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless($request->user() && in_array($request->user()->role, $roles, true), 403);

        return $next($request);
    }
}
