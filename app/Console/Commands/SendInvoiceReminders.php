<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\Invoice;
use App\Models\InvoiceSend;
use App\Services\InvoiceReminderRules;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class SendInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-reminders';

    protected $description = 'Send automatic payment reminders for unpaid, sent invoices.';

    public function handle(): void
    {
        if (! config('invoicing.automated_sends_enabled')) {
            return;
        }

        $today = Carbon::now('America/New_York')->startOfDay();

        // Only 'sent' invoices ever qualify -- draft and paid are excluded
        // by the status filter itself, and this app has no 'void'/cancelled
        // invoice status to worry about.
        Invoice::query()
            ->where('status', 'sent')
            ->whereNotNull('due_on')
            ->with('company', 'contact', 'payments')
            ->chunkById(50, function ($invoices) use ($today) {
                foreach ($invoices as $invoice) {
                    $this->considerInvoice($invoice, $today);
                }
            });
    }

    private function considerInvoice(Invoice $invoice, Carbon $today): void
    {
        // Re-checked here, immediately before deciding anything for this
        // specific invoice -- not just relying on the query's status filter
        // from the top of the run, per the "re-check paid status right
        // before each individual send" requirement.
        if ($invoice->fresh()->status === 'paid') {
            return;
        }

        if (! $invoice->effectiveRemindersEnabled()) {
            return;
        }

        // Signed by default (Carbon 3): positive when $today is after
        // $dueDay (overdue), negative when before -- exactly the offset
        // convention InvoiceReminderRules expects.
        $dueDay = Carbon::parse($invoice->due_on)->startOfDay();
        $offsetDays = (int) $dueDay->diffInDays($today);

        $rule = InvoiceReminderRules::ruleForOffset($offsetDays);

        if (! $rule) {
            return;
        }

        $lastSend = $invoice->invoiceSends()->where('type', InvoiceSend::TYPE_EMAIL)->latest('id')->first();

        try {
            $invoiceSend = $invoice->invoiceSends()->create([
                'type' => InvoiceSend::TYPE_REMINDER,
                'reminder_rule' => $rule,
                'status' => InvoiceSend::STATUS_QUEUED,
                'subject' => $this->subjectFor($invoice, $offsetDays),
                'message' => $this->messageFor($invoice, $offsetDays),
                'cc' => $lastSend?->cc,
            ]);
        } catch (QueryException) {
            // Unique index on (invoice_id, reminder_rule) -- this rule
            // already went out for this invoice (e.g. the command ran
            // twice). Nothing more to do.
            return;
        }

        SendInvoiceEmailJob::dispatch($invoiceSend->id);
    }

    private function subjectFor(Invoice $invoice, int $offsetDays): string
    {
        return match (true) {
            $offsetDays < 0 => "Reminder: invoice #{$invoice->invoice_number} is due in ".abs($offsetDays).' days',
            $offsetDays === 0 => "Reminder: invoice #{$invoice->invoice_number} is due today",
            default => "Invoice #{$invoice->invoice_number} is now {$offsetDays} days overdue",
        };
    }

    private function messageFor(Invoice $invoice, int $offsetDays): string
    {
        $balance = number_format($invoice->remainingBalance(), 2);
        $firm = $invoice->company->name;

        $opening = match (true) {
            $offsetDays < 0 => "This is a friendly reminder that invoice #{$invoice->invoice_number} from {$firm} is due on {$invoice->formattedDueOn()}.",
            $offsetDays === 0 => "This is a friendly reminder that invoice #{$invoice->invoice_number} from {$firm} is due today.",
            default => "Invoice #{$invoice->invoice_number} from {$firm} was due on {$invoice->formattedDueOn()} and is now {$offsetDays} days overdue.",
        };

        return "{$opening} The remaining balance is \${$balance}. If you have any questions or would like to discuss, feel free to reach out.";
    }
}
