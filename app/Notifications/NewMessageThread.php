<?php

namespace App\Notifications;

use App\Models\Contact;
use App\Models\Message;
use App\Services\MagicLinkBroker;
use App\Services\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageThread extends Notification
{
    use Queueable;

    public function __construct(protected Message $thread) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->thread->project;
        $sender = $this->thread->sender();
        $snippet = $this->thread->loadMissing('attachments')->snippet();

        return EmailTemplates::mail('message_new', [
            'first_name' => EmailTemplates::firstName($notifiable->name ?? null),
            'sender' => $sender?->name,
            'project' => $project->name,
            'subject' => $this->thread->subject,
            'snippet' => $snippet,
        ], $this->urlFor($notifiable, $project->id));
    }

    protected function urlFor(object $notifiable, int $projectId): string
    {
        if ($notifiable instanceof Contact) {
            return app(MagicLinkBroker::class)->issueSignedUrl(
                $notifiable,
                redirect: "/portal/projects/{$projectId}",
                minutes: MagicLinkBroker::MESSAGE_TTL_MINUTES,
                replace: false,
            );
        }

        return url("/projects/{$projectId}");
    }
}
