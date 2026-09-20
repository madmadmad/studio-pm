<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientMagicLink extends Notification
{
    use Queueable;

    public function __construct(protected string $url, protected bool $firstInvite = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject(
            $this->firstInvite ? 'Welcome to your Studio PM client hub' : 'Your Studio PM sign-in link'
        )->greeting('Hi '.$notifiable->name.',');

        if ($this->firstInvite) {
            $message->line("You've been invited to the client hub, where you can follow your project's tasks, proposals, and invoices.");
        } else {
            $message->line("Here's the sign-in link you requested.");
        }

        return $message
            ->action('Sign in', $this->url)
            ->line('This link expires in 20 minutes and can only be used once. If you didn\'t request this, you can ignore this email.');
    }
}
