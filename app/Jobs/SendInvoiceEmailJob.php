<?php

namespace App\Jobs;

use App\Mail\InvoiceEmail;
use App\Models\InvoiceSend;
use App\Models\User;
use App\Notifications\InvoiceSendAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

// The single place an invoice email actually goes out -- used for an
// immediate manual send, a scheduled send once its time arrives, and a
// reminder. Always reloads the invoice fresh so the email reflects the
// latest amounts/PDF at send time, never a snapshot from when the send was
// created or scheduled.
class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $invoiceSendId) {}

    public function handle(): void
    {
        $invoiceSend = InvoiceSend::find($this->invoiceSendId);

        if (! $invoiceSend || in_array($invoiceSend->status, [InvoiceSend::STATUS_CANCELLED, InvoiceSend::STATUS_SENT], true)) {
            return;
        }

        $invoice = $invoiceSend->invoice()->getModel()->newQuery()
            ->with(['company.contacts', 'contact', 'items', 'payments'])
            ->find($invoiceSend->invoice_id);

        // Final safety net -- the dispatch command already checks this
        // before creating/queuing the job, but re-checking here closes the
        // gap between that check and the email actually going out.
        if (! $invoice || $invoice->status === 'paid') {
            $invoiceSend->update(['status' => InvoiceSend::STATUS_CANCELLED, 'failure_reason' => 'Invoice was paid before this send went out.']);

            return;
        }

        $recipient = $invoice->billingContact();

        if (! $recipient?->email) {
            $invoiceSend->update(['status' => InvoiceSend::STATUS_CANCELLED, 'failure_reason' => 'Invoice has no contact with an email address.']);

            return;
        }

        try {
            $mail = new InvoiceEmail(
                invoice: $invoice,
                emailSubject: $invoiceSend->subject ?? "Invoice #{$invoice->invoice_number}",
                body: $invoiceSend->message ?? '',
                ccAddresses: $invoiceSend->cc ?? [],
                reminderLabel: $invoiceSend->type === InvoiceSend::TYPE_REMINDER ? $this->reminderLabel($invoiceSend->reminder_rule) : null,
            );

            Mail::to($recipient->email)->send($mail);

            $invoiceSend->update([
                'status' => InvoiceSend::STATUS_SENT,
                'sent_at' => now(),
                'recipients' => [$recipient->email],
            ]);

            if (! $invoice->sent_at) {
                $invoice->update(['sent_at' => now()]);
            }

            if ($invoice->status !== 'sent' && $invoice->status !== 'paid') {
                $invoice->update(['status' => 'sent']);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to send invoice email', [
                'invoice_send_id' => $invoiceSend->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $invoiceSend->update(['status' => InvoiceSend::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);

            Notification::send(User::where('role', User::ROLE_MANAGER)->get(), new InvoiceSendAlert($invoice, 'failed', $e->getMessage()));
        }
    }

    private function reminderLabel(?string $rule): string
    {
        return match ($rule) {
            'due_minus_3' => 'Payment reminder',
            'due_0' => 'Due today',
            default => str_starts_with((string) $rule, 'due_plus_') ? 'Overdue' : 'Payment reminder',
        };
    }
}
