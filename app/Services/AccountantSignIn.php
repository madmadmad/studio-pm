<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccountantSignInLink;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// The accountant's way in: a link by email, used once, that stops working
// after a while -- no password. Each new link replaces the last. The link
// is both signed (so it can't be altered) and carries a random token,
// stored hashed, that's cleared the moment it's used.
class AccountantSignIn
{
    // A link they asked for on the sign-in page.
    public const REQUEST_MINUTES = 20;

    // The invitation, which may sit in an inbox a while.
    public const INVITE_MINUTES = 7 * 24 * 60;

    // Emails a fresh link to this accountant.
    public function send(User $user, bool $invitation = false): void
    {
        abort_unless($user->isAccountant(), 422, 'Only the accountant signs in by link.');

        $minutes = $invitation ? self::INVITE_MINUTES : self::REQUEST_MINUTES;
        $token = Str::random(64);
        $user->forceFill([
            'login_token' => hash('sha256', $token),
            'login_token_expires_at' => now()->addMinutes($minutes),
        ])->save();

        $url = URL::temporarySignedRoute('login.link.verify', now()->addMinutes($minutes), ['user' => $user->id, 'token' => $token]);

        $user->notify(new AccountantSignInLink($url, $invitation ? '7 days' : '20 minutes', $invitation));
    }

    // The accountant this link signs in, or null -- used, replaced, expired
    // or not theirs. A good link works once.
    public function redeem(User $user, string $token): ?User
    {
        $valid = $user->isAccountant()
            && $user->isActive()
            && $user->login_token !== null
            && hash_equals($user->login_token, hash('sha256', $token))
            && $user->login_token_expires_at?->isFuture();

        if (! $valid) {
            return null;
        }

        $user->forceFill(['login_token' => null, 'login_token_expires_at' => null])->save();

        return $user;
    }
}
