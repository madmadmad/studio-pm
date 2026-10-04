<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Notifications\ClientMagicLink;
use App\Services\MagicLinkBroker;
use Illuminate\Http\Request;

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

        // Good for a week, unlike a requested sign-in link's 20 minutes.
        $url = $this->links->issueSignedUrl($contact, minutes: MagicLinkBroker::INVITE_TTL_MINUTES);

        $contact->notify(new ClientMagicLink($url, firstInvite: true, expiresInMinutes: MagicLinkBroker::INVITE_TTL_MINUTES));

        return $contact->fresh();
    }
}
