<?php

namespace App\Notifications;

use App\Models\Contact;
use App\Models\Message;
use App\Services\MagicLinkBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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
        $snippet = Str::limit($this->thread->body, 200);

        return (new MailMessage)
            ->subject("New message on {$project->name}: {$this->thread->subject}")
            ->greeting("New message from {$sender?->name} on {$project->name}")
            ->line($this->thread->subject)
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
