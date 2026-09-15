<?php

namespace App\Notifications;

use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProposalAccepted extends Notification
{
    use Queueable;

    public function __construct(public Proposal $proposal)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Proposal accepted: {$this->proposal->title}")
            ->line("{$this->proposal->company->name} just accepted \"{$this->proposal->title}\".")
            ->line('Estimate: $' . number_format($this->proposal->estimate_amount ?? 0, 2))
            ->action('View proposal', url('/proposals/' . $this->proposal->id));
    }
}
