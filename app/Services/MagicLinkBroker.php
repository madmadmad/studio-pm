<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MagicLinkBroker
{
    // A sign-in link someone asked for.
    const TTL_MINUTES = 20;

    // The link in a new-message or reply email: opened whenever it's read.
    const MESSAGE_TTL_MINUTES = 3 * 24 * 60;

    // The invitation the studio sends: it may sit in an inbox a while.
    const INVITE_TTL_MINUTES = 7 * 24 * 60;

    /**
     * Issue a fresh magic link token for a contact, invalidating any
     * previous unused one first -- only one live link per contact at a time.
     * Message emails pass `replace: false`, so a later message doesn't break
     * the link in an earlier one still sitting in the inbox.
     */
    public function issue(Contact $contact, int $minutes = self::TTL_MINUTES, bool $replace = true): string
    {
        if ($replace) {
            $contact->magicLinks()->whereNull('used_at')->delete();
        }

        $rawToken = Str::random(40);

        $contact->magicLinks()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return $rawToken;
    }

    /**
     * Issue a token and build the full signed sign-in URL in one step --
     * every notification that needs to get a contact into the portal
     * (invite, "sign in" request, or straight to a specific page like a
     * message thread) goes through this rather than re-deriving the same
     * temporarySignedRoute call.
     */
    public function issueSignedUrl(Contact $contact, ?string $redirect = null, int $minutes = self::TTL_MINUTES, bool $replace = true): string
    {
        $rawToken = $this->issue($contact, $minutes, $replace);

        return URL::temporarySignedRoute('portal.verify', now()->addMinutes($minutes), array_filter([
            'contactId' => $contact->id,
            'token' => $rawToken,
            'redirect' => $redirect,
        ]));
    }

    /**
     * Consume a token for a specific contact. This is the entire security
     * boundary for client login, so every check happens inside one locked
     * transaction: a double-click (two near-simultaneous requests for the
     * same link) can't both succeed, because the second request's row lock
     * waits for the first to commit its used_at write before it can even
     * read the row.
     */
    public function consume(Contact $contact, string $rawToken): MagicLinkResult
    {
        return DB::transaction(function () use ($contact, $rawToken) {
            $link = $contact->magicLinks()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if (! $link) {
                return MagicLinkResult::Invalid;
            }

            if ($link->isUsed()) {
                return MagicLinkResult::AlreadyUsed;
            }

            if ($link->isExpired()) {
                return MagicLinkResult::Expired;
            }

            $link->update(['used_at' => now()]);

            return MagicLinkResult::Valid;
        });
    }
}
