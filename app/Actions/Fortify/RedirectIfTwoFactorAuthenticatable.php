<?php

namespace App\Actions\Fortify;

use App\Support\TrustedDevice;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirect;

// Fortify's step that asks for a two-factor code after the password -- but
// not on a device the person chose to remember for 30 days (TrustedDevice).
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirect
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user->two_factor_confirmed_at && TrustedDevice::trusts($request, $user)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
