<?php

namespace App\Notifications;

use App\Models\Proposal;
use App\Services\EmailTemplates;
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
        return EmailTemplates::mail('proposal_accepted', [
            'first_name' => EmailTemplates::firstName($notifiable->name ?? null),
            'client' => $this->proposal->company->name,
            'proposal' => $this->proposal->title,
            'estimate' => '$'.number_format($this->proposal->estimate_amount ?? 0, 2),
        ], url('/proposals/'.$this->proposal->id.'/edit'));
    }
}
