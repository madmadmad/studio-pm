<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Proposal;
use App\Notifications\ClientMagicLink;
use App\Notifications\NewMessageReply;
use App\Notifications\NewMessageThread;
use App\Notifications\ProposalAccepted;
use App\Notifications\StaffInvitation;
use App\Services\MagicLinkBroker;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;

// The notification emails in a browser tab, for styling their shared
// template (resources/views/vendor/notifications and vendor/mail) --
// local only; see routes/web.php. Each is rendered for the signed-in
// staff member from the latest matching record, so nothing is created
// or sent.
class NotificationPreviewController extends Controller
{
    public const TYPES = ['message', 'reply', 'proposal-accepted', 'staff-invite', 'client-invite', 'password-reset'];

    public function show(Request $request, string $type = 'message')
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $user = $request->user();

        $notification = match ($type) {
            'message' => new NewMessageThread(Message::whereNull('parent_id')->latest('sent_at')->firstOrFail()),
            'reply' => new NewMessageReply(Message::whereNotNull('parent_id')->latest('sent_at')->firstOrFail()),
            'proposal-accepted' => new ProposalAccepted(Proposal::latest('id')->firstOrFail()),
            'staff-invite' => new StaffInvitation('preview-token'),
            'client-invite' => new ClientMagicLink(url('/portal/login/preview'), firstInvite: true, expiresInMinutes: MagicLinkBroker::INVITE_TTL_MINUTES),
            'password-reset' => new ResetPassword('preview-token'),
        };

        // Client emails go to a contact, as they would for real.
        $recipient = $type === 'client-invite' ? (Contact::whereNotNull('email')->first() ?? $user) : $user;
        $html = $notification->toMail($recipient)->render();

        // A strip to step between them, above the email itself.
        $links = collect(self::TYPES)->map(fn ($t) => $t === $type
            ? "<strong>{$t}</strong>"
            : '<a href="'.url("/dev/mail/notification/{$t}").'">'.$t.'</a>')->implode(' &middot; ');

        return preg_replace('/<body[^>]*>/', '$0<div style="font:13px sans-serif;padding:10px 16px;background:#fff8d6;border-bottom:1px solid #e5d98a">Preview: '.$links.'</div>', $html, 1);
    }
}
