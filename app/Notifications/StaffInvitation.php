<?php

namespace App\Notifications;

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

        return (new MailMessage)
            ->subject('You\'ve been invited to Studio PM')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('You\'ve been invited to join the Studio PM workspace.')
            ->action('Set your password', $url)
            ->line('This invite link expires in 7 days. If you weren\'t expecting this, you can ignore this email.');
    }
}
