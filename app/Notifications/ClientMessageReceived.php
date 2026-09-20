<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientMessageReceived extends Notification
{
    use Queueable;

    public function __construct(protected Message $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->message->project;
        $sender = $this->message->senderContact;

        return (new MailMessage)
            ->subject("New client message on {$project->name}")
            ->greeting('New message from '.$sender->name.':')
            ->line($this->message->body)
            ->action('View project', url("/projects/{$project->id}"));
    }
}
