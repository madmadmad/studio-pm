<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Nothing here is for search engines: the staff app is behind sign-in, and
// the client pages (invoice and proposal links, the portal) are private
// even though they're public URLs. Every response -- pages, PDFs, files --
// asks not to be indexed, along with public/robots.txt asking crawlers to
// stay out.
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
