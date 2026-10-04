<?php

namespace App\Notifications;

use App\Services\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification
{
    use Queueable;

    public function __construct(protected string $rawToken) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/invite/'.$this->rawToken.'?email='.urlencode($notifiable->email));

        return EmailTemplates::mail('staff_invite', [
            'first_name' => EmailTemplates::firstName($notifiable->name ?? null),
            'expiry' => '7 days',
        ], $url);
    }
}
