<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Support\TrustedDevice;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        // Asks for the two-factor code after the password -- except on a
        // device remembered for 30 days (App\Support\TrustedDevice).
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        // A good code with "Remember this device" ticked: no code here for
        // 30 days. A recovery code counts as a good code.
        $rememberDevice = function ($event) {
            if (request()->boolean('remember_device')) {
                TrustedDevice::remember($event->user);
            }
        };
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, $rememberDevice);
        Event::listen(RecoveryCodeReplaced::class, $rememberDevice);

        // Signing in confirms the password for a while (auth.password_timeout),
        // so setting up two-factor straight after doesn't ask for it again.
        // Staff only: the accountant has no password to have confirmed.
        Event::listen(Login::class, function (Login $event) {
            if (request()->hasSession() && $event->user instanceof User && $event->user->isStaff()) {
                request()->session()->put('auth.password_confirmed_at', time());
            }
        });

        // A passkey signs staff in on its own (it's both factors at once) --
        // never a deactivated account.
        Passkeys::authorizeLoginUsing(fn (Request $request, $user) => $user instanceof User && $user->isStaff() && $user->isActive());

        Fortify::loginView(fn () => Inertia::render('Auth/Login'));

        // The usual email and password -- except for the accountant, who
        // signs in by emailed link only (AccountantSignInController).
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->input(Fortify::username()))->first();

            return $user && ! $user->isAccountant() && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
