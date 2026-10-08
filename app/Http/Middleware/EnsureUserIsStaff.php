<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    /**
     * Staff-only areas (Chat): the request must be an active staff User,
     * never a Client Hub Contact. The `auth:web`/`auth:sanctum` guards in
     * front of this already only resolve Users -- this is the belt to their
     * braces, so a route moved outside those groups still can't leak.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive(), 403);

        return $next($request);
    }
}
