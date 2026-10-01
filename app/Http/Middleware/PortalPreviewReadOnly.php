<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// While a manager previews a client's portal (PortalPreviewController),
// the portal is look-only: nothing can be posted, edited or deleted as the
// client. Signing out ends the preview instead (PortalAuthController).
class PortalPreviewReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $preview = $request->hasSession() ? $request->session()->get('portal_preview') : null;

        // Gone stale -- the portal session is someone else's, or no one's.
        if ($preview && $request->user('client')?->id !== $preview['contact_id']) {
            $request->session()->forget('portal_preview');
            $preview = null;
        }

        if ($preview && ! $request->isMethodSafe() && ! $request->routeIs('portal.logout', 'portal.preview.exit')) {
            $message = 'This is a staff preview of the portal, so nothing can be changed here.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 403)
                : back()->with('error', $message);
        }

        return $next($request);
    }
}
