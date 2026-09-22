<?php

namespace App\Notifications;

use App\Models\Contact;
use App\Models\Message;
use App\Services\MagicLinkBroker;
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

        return (new MailMessage)
            ->subject("New reply on {$project->name}: {$thread->subject}")
            ->greeting("{$sender?->name} replied on {$project->name}")
            ->line($thread->subject)
            ->line($snippet)
            ->action('View and reply', $this->urlFor($notifiable, $project->id));
    }

    protected function urlFor(object $notifiable, int $projectId): string
    {
        if ($notifiable instanceof Contact) {
            return app(MagicLinkBroker::class)->issueSignedUrl($notifiable, redirect: "/portal/projects/{$projectId}");
        }

        return url("/projects/{$projectId}");
    }
}
