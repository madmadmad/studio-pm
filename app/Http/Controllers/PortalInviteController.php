<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Notifications\ClientMagicLink;
use App\Services\MagicLinkBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// A contact's Client Hub access, from their card on the client's page:
// invite, send the invite again, or take access away.
class PortalInviteController extends Controller
{
    public function __construct(protected MagicLinkBroker $links) {}

    public function store(Request $request, Contact $contact)
    {
        abort_if($contact->hasPortalAccess(), 422, 'This contact already has portal access.');
        abort_unless($contact->email, 422, 'This contact needs an email address before they can be invited.');

        $contact->forceFill([
            'portal_invited_at' => now(),
            'portal_invited_by' => $request->user()->id,
        ])->save();

        $this->sendInvite($contact);

        return $contact->fresh();
    }

    // The invite email again, with a fresh week-long link -- for one that
    // expired or got buried. Replaces any unused link they already have.
    public function resend(Contact $contact)
    {
        abort_unless($contact->hasPortalAccess(), 422, 'This contact hasn’t been invited to the portal.');
        abort_unless($contact->email, 422, 'This contact needs an email address before an invite can be sent.');

        $this->sendInvite($contact);

        return $contact->fresh();
    }

    // Takes portal access away without deleting the contact (their messages,
    // billing role and the rest stay). Unused sign-in links are dropped and
    // the remember-me token rotated; EnsureContactHasPortalAccess signs out
    // any portal session still open. They can be invited again later.
    public function destroy(Contact $contact)
    {
        abort_unless($contact->hasPortalAccess(), 422, 'This contact doesn’t have portal access.');

        $contact->magicLinks()->whereNull('used_at')->delete();
        $contact->forceFill([
            'portal_invited_at' => null,
            'portal_invited_by' => null,
            'remember_token' => Str::random(60),
        ])->save();

        return $contact->fresh();
    }

    // Good for a week, unlike a requested sign-in link's 20 minutes.
    private function sendInvite(Contact $contact): void
    {
        $url = $this->links->issueSignedUrl($contact, minutes: MagicLinkBroker::INVITE_TTL_MINUTES);

        $contact->notify(new ClientMagicLink($url, firstInvite: true, expiresInMinutes: MagicLinkBroker::INVITE_TTL_MINUTES));
    }
}
