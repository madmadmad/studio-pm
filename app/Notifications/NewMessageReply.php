<?php

namespace App\Notifications;

use App\Models\Contact;
use App\Models\Message;
use App\Services\MagicLinkBroker;
use App\Services\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageReply extends Notification
{
    use Queueable;

    public function __construct(protected Message $reply) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $thread = $this->reply->parent;
        $project = $thread->project;
        $sender = $this->reply->sender();
        $snippet = $this->reply->loadMissing('attachments')->snippet();

        return EmailTemplates::mail('message_reply', [
            'first_name' => EmailTemplates::firstName($notifiable->name ?? null),
            'sender' => $sender?->name,
            'project' => $project->name,
            'subject' => $thread->subject,
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
