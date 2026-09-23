<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
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
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('invoices:dispatch-scheduled-sends')->everyFiveMinutes();
        $schedule->command('invoices:send-reminders')->dailyAt('09:00')->timezone('America/New_York');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias(['role' => EnsureUserHasRole::class]);
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
    })->create();
