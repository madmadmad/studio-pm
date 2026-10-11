<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The accountant's whole app is this list: Bookkeeping (every report and
// export), invoices and expenses to look at (an invoice, its PDF, a
// receipt), posting and reversing journal entries, their alerts and
// Appearance, and signing out. Anything else -- a page or an action,
// existing or added later -- is closed to them: a page sends them to
// Bookkeeping, anything else is refused. Everyone else passes straight
// through. Sits on the staff app's web and API route groups.
class RestrictAccountant
{
    // [method, route URI]; a trailing * matches anything after it.
    private const ALLOWED = [
        ['GET', 'bookkeeping'],
        ['GET', 'bookkeeping/*'],
        ['GET', 'invoices'],
        ['GET', 'invoices/{invoice}'],
        ['GET', 'invoices/{invoice}/pdf'],
        ['GET', 'expenses'],
        ['POST', 'logout'],

        ['GET', 'api/invoices/{invoice}'],
        ['GET', 'api/invoice-categories'],
        ['GET', 'api/expenses/{expense}/receipt'],
        ['GET', 'api/bookkeeping/*'],
        ['GET', 'api/transactions'],
        ['POST', 'api/journal-entries'],
        ['POST', 'api/journal-entries/{journalEntry}/reverse'],
        ['GET', 'api/notifications'],
        ['POST', 'api/notifications/{notification}/read'],
        ['PATCH', 'api/profile/appearance'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAccountant() || $this->allowed($request)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect('/bookkeeping');
        }

        abort(403, 'Not available to the accountant.');
    }

    private function allowed(Request $request): bool
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $uri = $request->route()?->uri() ?? '';

        foreach (self::ALLOWED as [$allowedMethod, $pattern]) {
            if ($method !== $allowedMethod) {
                continue;
            }
            if (str_ends_with($pattern, '*') ? str_starts_with($uri, rtrim($pattern, '*')) : $uri === $pattern) {
                return true;
            }
        }

        return false;
    }
}
