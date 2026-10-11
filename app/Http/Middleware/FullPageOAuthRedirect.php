<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// Signing in on the way to approving an app (Passport's /oauth/authorize):
// the sign-in and two-factor pages are Inertia's, and an Inertia request
// that's redirected to a plain page shows it in a dev overlay instead of
// going there. Such a redirect becomes a full page visit.
class FullPageOAuthRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->header('X-Inertia') && $response instanceof RedirectResponse
            && str_starts_with((string) parse_url($response->getTargetUrl(), PHP_URL_PATH), '/oauth/')) {
            return Inertia::location($response->getTargetUrl());
        }

        return $response;
    }
}
