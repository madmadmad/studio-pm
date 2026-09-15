<?php

namespace App\Mail;

use App\Models\Message as ProjectMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ProjectMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->message->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project-message',
            with: ['body' => $this->message->body],
        );
    }
}
