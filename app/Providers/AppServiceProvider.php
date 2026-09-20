<?php

namespace App\Providers;

use App\Listeners\MarkInvoicePaidFromStripeWebhook;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TimeEntryPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(WebhookReceived::class, MarkInvoicePaidFromStripeWebhook::class);

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8));

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TimeEntry::class, TimeEntryPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // One magic-link request per email per minute -- enough to stop
        // spam without blocking a client who genuinely missed the email.
        RateLimiter::for('magic-link', function (Request $request) {
            $key = Str::transliterate(Str::lower($request->input('email', 'unknown')));

            return Limit::perMinute(1)->by($key);
        });
    }
}
