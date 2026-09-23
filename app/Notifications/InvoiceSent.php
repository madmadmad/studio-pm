<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceSent extends Notification
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pdf = InvoicePdfRenderer::render($this->invoice);

        $dueLine = "Due {$this->invoice->formattedDueOn()}";
        if ($termsLabel = $this->invoice->paymentTermsLabel()) {
            $dueLine .= " ({$termsLabel})";
        }

        return (new MailMessage)
            ->subject("Invoice #{$this->invoice->invoice_number} from {$this->invoice->company->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("A new invoice for \${$this->formattedTotal()} is ready.")
            ->line($dueLine)
            ->action('View invoice', url('/i/'.$this->invoice->public_token))
            ->line('The invoice is also attached to this email as a PDF.')
            ->attachData($pdf->output(), "invoice-{$this->invoice->invoice_number}.pdf", [
                'mime' => 'application/pdf',
            ]);
    }

    protected function formattedTotal(): string
    {
        return number_format($this->invoice->total(), 2);
    }
}
