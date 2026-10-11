<?php

namespace App\Providers;

use App\Listeners\MarkInvoicePaidFromStripeWebhook;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\MessagePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProposalPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TimeEntryPolicy;
use App\Policies\UserPolicy;
use App\Services\EmailTemplates;
use App\Services\Posting\PostingHooks;
use Carbon\CarbonInterval;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Passport\Passport;

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
        // Blocks migrate:fresh/refresh/reset and db:wipe from ever running
        // in production, even with --force -- schema changes there must go
        // through additive `migrate`, never a reset.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // The MCP server's OAuth (routes/ai.php): a day per access token,
        // renewed by its client for a month -- so taking someone's access
        // away (or deactivating them) bites within the day.
        Passport::tokensExpireIn(CarbonInterval::day());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        // Approving a client is a staff member's to do, with two-factor set
        // up like everything else (RequireTwoFactor) -- Passport's screen
        // sits outside the app's own route groups, so it's checked here.
        Passport::authorizationView(function (array $parameters) {
            $user = $parameters['user'];
            abort_unless($user instanceof User && $user->isStaff() && $user->isActive(), 403, 'Only staff can connect apps.');

            if (config('auth.require_two_factor') && ! $user->two_factor_confirmed_at) {
                return redirect()->route('security.setup');
            }

            return view('mcp.authorize', $parameters);
        });

        // Cashier's WebhookController only verifies the Stripe-Signature
        // header when a webhook secret is configured -- deliberately unset
        // locally (see .env.example) so devs who aren't testing payments
        // never need the Stripe CLI. Refuse to boot in production without
        // it, since that would let anyone POST a fake "paid" webhook.
        if ($this->app->isProduction() && ! config('cashier.webhook.secret')) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET must be set in production.');
        }

        Event::listen(WebhookReceived::class, MarkInvoicePaidFromStripeWebhook::class);

        // Expenses, payments and income post to the ledger themselves.
        PostingHooks::register();

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8));

        // The password-reset email, worded like the other notifications
        // (Settings > Notification emails) instead of Laravel's stock text.
        ResetPassword::toMailUsing(fn ($user, string $token) => EmailTemplates::mail('password_reset', [
            'first_name' => EmailTemplates::firstName($user->name),
            'expiry' => EmailTemplates::duration((int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire')),
        ], url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false))));

        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Proposal::class, ProposalPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TimeEntry::class, TimeEntryPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // One magic-link request per email per minute -- enough to stop
        // spam without blocking a client who genuinely missed the email.
        RateLimiter::for('magic-link', function (Request $request) {
            $key = Str::transliterate(Str::lower($request->input('email', 'unknown')));

            return Limit::perMinute(1)->by($key);
        });

        // One manual invoice send per invoice per minute -- guards against
        // an accidental double-click, not a real attack surface (the route
        // is already manager-only). The route parameter may not have been
        // substituted into an Invoice model yet at this point in the
        // middleware stack, so this handles either form.
        RateLimiter::for('invoice-send', function (Request $request) {
            $invoice = $request->route('invoice');

            return Limit::perMinute(1)->by(is_object($invoice) ? $invoice->id : $invoice);
        });
    }
}
