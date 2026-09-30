<?php

namespace App\Mail;

use App\Models\Proposal;
use App\Models\StudioProfile;
use App\Services\ProposalPdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

// A proposal sent to its client from the Send Proposal dialog: the
// message, a summary (title, estimate), a button to review and accept it
// online, and the proposal attached as a PDF. Modeled on InvoiceEmail.
class ProposalEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $ccAddresses
     */
    public function __construct(
        public Proposal $proposal,
        public string $emailSubject,
        public string $body,
        public array $ccAddresses = [],
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = StudioProfile::current()->email;

        return new Envelope(
            subject: $this->emailSubject,
            cc: $this->ccAddresses,
            replyTo: $replyTo ? [$replyTo] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proposal',
            with: [
                'proposal' => $this->proposal,
                'studio' => StudioProfile::current(),
                'body' => $this->body,
                'viewUrl' => url('/p/'.$this->proposal->accept_token),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = ProposalPdfRenderer::render($this->proposal->loadMissing('items', 'company', 'project'));

        // Letters, digits and spaces only, so the name is safe everywhere
        // (as InvoiceEmail does).
        $firmName = preg_replace('/[^A-Za-z0-9 ]/', '', StudioProfile::current()->name);
        $firmSlug = Str::studly($firmName ?: '') ?: 'Proposal';

        return [
            Attachment::fromData(fn () => $pdf->output(), "Proposal-{$firmSlug}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
