<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MagicLinkBroker
{
    const TTL_MINUTES = 20;

    /**
     * Issue a fresh magic link token for a contact, invalidating any
     * previous unused one first -- only one live link per contact at a time.
     */
    public function issue(Contact $contact): string
    {
        $contact->magicLinks()->whereNull('used_at')->delete();

        $rawToken = Str::random(40);

        $contact->magicLinks()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $rawToken;
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
