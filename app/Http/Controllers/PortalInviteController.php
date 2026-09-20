<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Notifications\ClientMagicLink;
use App\Services\MagicLinkBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

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

        $rawToken = $this->links->issue($contact);
        $url = URL::temporarySignedRoute('portal.verify', now()->addMinutes(MagicLinkBroker::TTL_MINUTES), [
            'contactId' => $contact->id,
            'token' => $rawToken,
        ]);

        $contact->notify(new ClientMagicLink($url, firstInvite: true));

        return $contact->fresh();
    }
}
