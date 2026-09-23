<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// Surfaces a failed manual/scheduled/reminder send, or a scheduled send that
// had to be skipped (invoice paid/deleted/lost its contact before the
// scheduled time), to every manager -- the only way to notice this for an
// invoice you aren't currently looking at.
class InvoiceSendAlert extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public string $kind, // 'failed' | 'skipped'
        public string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $verb = $this->kind === 'skipped' ? 'Scheduled send skipped' : 'Send failed';

        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'company_name' => $this->invoice->company->name,
            'kind' => $this->kind,
            'reason' => $this->reason,
            'message' => "{$verb} for invoice #{$this->invoice->invoice_number} ({$this->invoice->company->name}): {$this->reason}",
        ];
    }
}
