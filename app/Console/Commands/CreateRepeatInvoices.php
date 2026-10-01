<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceSend;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// Repeating invoices (set from the send dialog): each series whose next
// date has come gets its copy -- issued that day -- with its email
// scheduled for 9:00 AM Eastern that day, so the scheduled-sends command
// sends it (and it can be checked or changed before then). A series that
// fell behind catches up one copy per run.
class CreateRepeatInvoices extends Command
{
    protected $signature = 'invoices:create-repeats';

    protected $description = 'Create and schedule the next invoice of each repeating series that is due.';

    public function handle(): void
    {
        Invoice::query()
            ->whereNotNull('repeat')
            ->whereDate('next_repeat_on', '<=', today())
            ->with('items', 'company')
            ->each(function (Invoice $series) {
                $issuedOn = $series->next_repeat_on->copy();
                $copy = $series->makeRepeat($issuedOn);
                $email = $series->repeatEmailFor($copy->load('items'));

                $copy->invoiceSends()->create([
                    'type' => InvoiceSend::TYPE_EMAIL,
                    'status' => InvoiceSend::STATUS_SCHEDULED,
                    'scheduled_for' => Carbon::parse($issuedOn->toDateString().' 09:00', 'America/New_York')->utc(),
                    'recipients' => array_filter([$copy->billingContact()?->email]),
                    'cc' => $email['cc'],
                    'subject' => $email['subject'],
                    'message' => $email['message'],
                ]);

                $this->info("Invoice #{$copy->invoice_number} created from #{$series->invoice_number}.");
            });
    }
}
