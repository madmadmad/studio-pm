<?php

use App\Exceptions\LedgerException;
use App\Http\Middleware\EnsureContactHasPortalAccess;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PortalPreviewReadOnly;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Chat's private and presence channels (routes/channels.php) are staff
    // only: /broadcasting/auth signs channels for an active staff User on the
    // web guard, never a Client Hub contact (whose session lives on the
    // `client` guard, sometimes alongside a staff one in a portal preview).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth:web', 'staff']])
    ->withSchedule(function (Schedule $schedule): void {
        // Repeating invoices' copies first, so their 9 AM sends are queued.
        $schedule->command('invoices:create-repeats')->dailyAt('06:00')->timezone('America/New_York');
        $schedule->command('invoices:dispatch-scheduled-sends')->everyFiveMinutes();
        $schedule->command('invoices:send-reminders')->dailyAt('09:00')->timezone('America/New_York');
        // Yesterday's bank charges into expenses, before the day starts.
        $schedule->command('plaid:sync')->dailyAt('05:00')->timezone('America/New_York');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias(['permission' => EnsureUserHasPermission::class, 'portal.preview' => PortalPreviewReadOnly::class, 'portal.access' => EnsureContactHasPortalAccess::class, 'staff' => EnsureUserIsStaff::class]);
        // Global, not just the web group -- a deactivated user's existing
        // session cookie could otherwise still hit the API guard directly.
        $middleware->append(EnsureUserIsActive::class);

        // Laravel's default guest-redirect always points at route('login'),
        // regardless of which guard failed -- override so an unauthenticated
        // Client Hub request lands on the portal's own sign-in page instead
        // of the staff login screen.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('portal*') ? route('portal.login') : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A ledger rule (a locked period, a posted entry) refused the
        // change: say so, like a validation error.
        $exceptions->render(fn (LedgerException $e, Request $request) => $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);
    })->create();
