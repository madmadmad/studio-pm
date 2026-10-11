<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

// "Remember this device for 30 days": after a good two-factor code, this
// browser gets a cookie that lets the next sign-ins skip the code -- still
// with the password, never instead of it. The cookie is encrypted (like
// every cookie here) and tied to the person's current authenticator: set
// up a new one, or have a super admin reset it, and every remembered
// device has to give a code again.
class TrustedDevice
{
    public const COOKIE = 'studio_trusted_device';

    public const DAYS = 30;

    public static function remember(User $user): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, json_encode([
            'user' => $user->id,
            'fingerprint' => self::fingerprint($user),
            'until' => now()->addDays(self::DAYS)->getTimestamp(),
        ]), self::DAYS * 24 * 60, httpOnly: true, sameSite: 'lax', secure: request()->isSecure()));
    }

    public static function trusts(Request $request, User $user): bool
    {
        $value = json_decode((string) $request->cookie(self::COOKIE), true);

        return is_array($value)
            && ($value['user'] ?? null) === $user->id
            && ($value['until'] ?? 0) > now()->getTimestamp()
            && hash_equals(self::fingerprint($user), (string) ($value['fingerprint'] ?? ''));
    }

    // Changes whenever the person's authenticator does.
    private static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->two_factor_secret, config('app.key'));
    }
}
