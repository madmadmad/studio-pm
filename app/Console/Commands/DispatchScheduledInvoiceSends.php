<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\InvoiceSend;
use App\Models\User;
use App\Notifications\InvoiceSendAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class DispatchScheduledInvoiceSends extends Command
{
    protected $signature = 'invoices:dispatch-scheduled-sends';

    protected $description = 'Dispatch invoice email sends whose scheduled time has arrived.';

    public function handle(): void
    {
        if (! config('invoicing.automated_sends_enabled')) {
            return;
        }

        InvoiceSend::query()
            ->where('type', InvoiceSend::TYPE_EMAIL)
            ->where('status', InvoiceSend::STATUS_SCHEDULED)
            // scheduled_for is stored in UTC (see App\Casts\UtcDateTime) --
            // query bindings bypass attribute casting, so this must be
            // compared against an explicitly UTC "now" too, not the app's
            // default America/New_York now().
            ->where('scheduled_for', '<=', now('UTC'))
            ->with('invoice.company.contacts', 'invoice.contact')
            ->each(function (InvoiceSend $invoiceSend) {
                // Invoice deletion cascades onto invoice_sends, so a
                // genuinely deleted invoice never reaches this loop at all
                // -- nothing left to skip or notify about.
                $invoice = $invoiceSend->invoice;

                if (! $invoice) {
                    return;
                }

                if ($invoice->status === 'paid') {
                    $this->skip($invoiceSend, $invoice, 'The invoice was paid before the scheduled send time.');

                    return;
                }

                if (! $invoice->billingContact()?->email) {
                    $this->skip($invoiceSend, $invoice, 'The invoice no longer has a contact with an email address.');

                    return;
                }

                $invoiceSend->update(['status' => InvoiceSend::STATUS_QUEUED]);
                SendInvoiceEmailJob::dispatch($invoiceSend->id);
            });
    }

    private function skip(InvoiceSend $invoiceSend, $invoice, string $reason): void
    {
        $invoiceSend->update(['status' => InvoiceSend::STATUS_CANCELLED, 'failure_reason' => $reason]);

        Notification::send(User::where('role', User::ROLE_MANAGER)->get(), new InvoiceSendAlert($invoice, 'skipped', $reason));
    }
}
