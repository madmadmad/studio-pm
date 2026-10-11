<?php

namespace App\Notifications;

use App\Models\StudioProfile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// The accountant's sign-in link (App\Services\AccountantSignIn): the
// invitation, or one they asked for on the sign-in page. Sent at once, not
// queued -- they're waiting on it.
class AccountantSignInLink extends Notification
{
    public function __construct(
        protected string $url,
        protected string $expiry,
        protected bool $invitation = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $studio = StudioProfile::brandName();
        $firstName = strtok(trim((string) $notifiable->name), ' ') ?: 'there';

        $mail = (new MailMessage)->greeting("Hi {$firstName},");

        $mail = $this->invitation
            ? $mail->subject("{$studio} has given you access to its books")
                ->line("{$studio} has set you up to see its bookkeeping, invoices and expenses.")
            : $mail->subject("Your sign-in link for {$studio}");

        return $mail
            ->action('Sign in', $this->url)
            ->line("The link works once and stops working after {$this->expiry}. When you need to sign in again, ask for a new one on the sign-in page -- there's no password.")
            ->line("If you weren't expecting this, you can ignore it.");
    }
}
