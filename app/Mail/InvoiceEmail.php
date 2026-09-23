<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\StudioProfile;
use App\Services\InvoicePdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

// Backs both a manual/scheduled send and an automatic reminder -- the only
// difference between the two is the subject/body text and whether a
// reminder label is shown, everything else (PDF, summary block, pay
// button, reply-to) is identical.
class InvoiceEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $ccAddresses
     */
    public function __construct(
        public Invoice $invoice,
        public string $emailSubject,
        public string $body,
        public array $ccAddresses = [],
        public ?string $reminderLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('invoicing.billing_reply_to') ?: StudioProfile::current()->email;

        return new Envelope(
            subject: $this->emailSubject,
            cc: $this->ccAddresses,
            replyTo: $replyTo ? [$replyTo] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'studio' => StudioProfile::current(),
                'body' => $this->body,
                'reminderLabel' => $this->reminderLabel,
                'amountDue' => $this->invoice->remainingBalance(),
                'payUrl' => url('/i/'.$this->invoice->public_token),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = InvoicePdfRenderer::render($this->invoice);

        // Str::studly() alone leaves punctuation like "&" untouched (e.g.
        // "Alder & Finch Design" -> "Alder&FinchDesign") -- strip
        // everything but letters/digits/spaces first so the attachment
        // name is always filesystem- and mail-client-safe.
        $firmName = preg_replace('/[^A-Za-z0-9 ]/', '', StudioProfile::current()->name);
        $firmSlug = Str::studly($firmName ?: '') ?: 'Invoice';

        return [
            Attachment::fromData(fn () => $pdf->output(), "Invoice-{$this->invoice->invoice_number}-{$firmSlug}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
