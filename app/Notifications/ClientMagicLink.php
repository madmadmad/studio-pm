<?php

namespace App\Notifications;

use App\Services\EmailTemplates;
use App\Services\MagicLinkBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientMagicLink extends Notification
{
    use Queueable;

    // `expiresInMinutes`: how long the link lasts -- a week for the
    // studio's invitation, 20 minutes for a link the client asked for.
    public function __construct(
        protected string $url,
        protected bool $firstInvite = false,
        protected int $expiresInMinutes = MagicLinkBroker::TTL_MINUTES,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The studio's invitation, or a sign-in link the client asked for.
        return EmailTemplates::mail($this->firstInvite ? 'client_invite' : 'client_sign_in', [
            'first_name' => EmailTemplates::firstName($notifiable->name ?? null),
            'expiry' => EmailTemplates::duration($this->expiresInMinutes),
        ], $this->url);
    }
}
