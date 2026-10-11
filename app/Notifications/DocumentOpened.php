<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\Proposal;
use Illuminate\Notifications\Notification;

// "Acme opened invoice #1042" in the alerts menu, the first time the
// client opens what you sent them (App\Support\DocumentViews).
class DocumentOpened extends Notification
{
    public function __construct(public Invoice|Proposal $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $client = $this->document->company?->name ?? 'The client';

        return $this->document instanceof Invoice
            ? [
                'invoice_id' => $this->document->id,
                'message' => "{$client} opened invoice #{$this->document->invoice_number}",
                'url' => "/invoices/{$this->document->id}",
            ]
            : [
                'proposal_id' => $this->document->id,
                'message' => "{$client} opened the proposal “{$this->document->title}”",
                'url' => "/proposals/{$this->document->id}/edit",
            ];
    }
}
