<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Notifications\ClientMagicLink;
use App\Services\MagicLinkBroker;
use App\Services\MagicLinkResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PortalAuthController extends Controller
{
    public function __construct(protected MagicLinkBroker $links) {}

    public function showRequest(): Response
    {
        return Inertia::render('Portal/Auth/RequestLink');
    }

    public function sendLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $contact = Contact::where('email', $data['email'])->whereNotNull('portal_invited_at')->first();

        if ($contact) {
            $rawToken = $this->links->issue($contact);
            $url = URL::temporarySignedRoute('portal.verify', now()->addMinutes(MagicLinkBroker::TTL_MINUTES), [
                'contactId' => $contact->id,
                'token' => $rawToken,
            ]);
            $contact->notify(new ClientMagicLink($url, firstInvite: $contact->last_login_at === null));
        }

        // Same response whether or not the email matched a portal contact --
        // this endpoint must not reveal who has portal access.
        return back()->with('status', 'If that email has portal access, a sign-in link is on its way.');
    }

    public function verify(Request $request, int $contactId, string $token)
    {
        if (! $request->hasValidSignature()) {
            return Inertia::render('Portal/Auth/LinkInvalid', ['reason' => 'invalid']);
        }

        $contact = Contact::find($contactId);

        if (! $contact) {
            return Inertia::render('Portal/Auth/LinkInvalid', ['reason' => 'invalid']);
        }

        $result = $this->links->consume($contact, $token);

        if ($result !== MagicLinkResult::Valid) {
            $reason = match ($result) {
                MagicLinkResult::AlreadyUsed => 'used',
                MagicLinkResult::Expired => 'expired',
                MagicLinkResult::Invalid => 'invalid',
            };

            return Inertia::render('Portal/Auth/LinkInvalid', ['reason' => $reason]);
        }

        $contact->forceFill([
            'remember_token_issued_at' => now(),
            'last_login_at' => now(),
        ])->save();

        Auth::guard('client')->login($contact, remember: true);
        $request->session()->regenerate();

        return redirect('/portal');
    }

    public function logout(Request $request)
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/portal/login');
    }
}
