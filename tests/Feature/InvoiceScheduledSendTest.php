<?php

namespace Tests\Feature;

use App\Mail\InvoiceEmail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSend;
use App\Models\User;
use App\Notifications\InvoiceSendAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvoiceScheduledSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(array $overrides = []): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'AP', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);
        $invoice = $company->invoices()->create(array_merge([
            'contact_id' => $contact->id, 'status' => 'draft', 'surcharge' => false,
            'issued_on' => now(), 'due_on' => now()->addDays(14),
        ], $overrides));
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_scheduling_a_send_creates_a_scheduled_invoice_send_row(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice();

        // 9am Eastern on a day that's 10pm UTC the day before -- exercises
        // the UTC/Eastern boundary explicitly.
        $scheduledFor = Carbon::create(2026, 10, 1, 13, 0, 0, 'UTC'); // 9am America/New_York

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email', 'subject' => 'Invoice', 'message' => 'Body',
            'schedule' => 'later', 'scheduled_for' => $scheduledFor->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoice_sends', [
            'invoice_id' => $invoice->id,
            'type' => InvoiceSend::TYPE_EMAIL,
            'status' => InvoiceSend::STATUS_SCHEDULED,
        ]);
        $this->assertSame($scheduledFor->timestamp, InvoiceSend::first()->scheduled_for->timestamp);
    }

    public function test_the_dispatch_command_sends_using_the_invoices_latest_content_at_send_time(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice();
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->subMinute(), 'recipients' => ['ap@alderfinch.co'],
            'subject' => 'Old subject', 'message' => 'Old body',
        ]);

        // Content changes after scheduling but before the scheduled time --
        // the email must reflect this, not a snapshot from when it was
        // scheduled.
        $invoice->items()->create(['description' => 'Extra revisions', 'amount' => 500]);

        $this->artisan('invoices:dispatch-scheduled-sends');

        $invoiceSend->refresh();
        $this->assertSame(InvoiceSend::STATUS_SENT, $invoiceSend->status);

        Mail::assertQueued(InvoiceEmail::class, fn (InvoiceEmail $mail) => $mail->invoice->total() === 1500.0);
    }

    public function test_a_pending_scheduled_send_can_be_cancelled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice();
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->addHour(), 'subject' => 's', 'message' => 'm',
        ]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/sends/{$invoiceSend->id}/cancel");

        $response->assertOk();
        $this->assertSame(InvoiceSend::STATUS_CANCELLED, $invoiceSend->fresh()->status);
    }

    public function test_a_pending_scheduled_send_can_be_rescheduled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice();
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->addHour(), 'subject' => 's', 'message' => 'm',
        ]);
        $newTime = now()->addDays(2);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/sends/{$invoiceSend->id}/reschedule", [
            'scheduled_for' => $newTime->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertSame($newTime->timestamp, $invoiceSend->fresh()->scheduled_for->timestamp);
    }

    public function test_a_pending_scheduled_send_can_be_sent_now(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $invoice = $this->makeInvoice();
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->addDay(), 'recipients' => ['ap@alderfinch.co'],
            'subject' => 's', 'message' => 'm',
        ]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/sends/{$invoiceSend->id}/send-now");

        $response->assertOk();
        $this->assertSame(InvoiceSend::STATUS_SENT, $invoiceSend->fresh()->status);
        Mail::assertQueued(InvoiceEmail::class);
    }

    public function test_a_scheduled_send_is_skipped_and_cancelled_if_the_invoice_was_paid_first(): void
    {
        Notification::fake();
        Mail::fake();

        $manager = User::factory()->create();
        $invoice = $this->makeInvoice(['status' => 'sent']);
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->subMinute(), 'subject' => 's', 'message' => 'm',
        ]);
        $invoice->recordPayment('check', $invoice->total());

        $this->artisan('invoices:dispatch-scheduled-sends');

        $this->assertSame(InvoiceSend::STATUS_CANCELLED, $invoiceSend->fresh()->status);
        Mail::assertNothingQueued();
        Notification::assertSentTo($manager, InvoiceSendAlert::class, fn ($n) => $n->kind === 'skipped');
    }

    public function test_a_scheduled_send_is_skipped_if_the_invoice_lost_its_contact_email(): void
    {
        Notification::fake();
        Mail::fake();

        User::factory()->create();
        $invoice = $this->makeInvoice();
        $invoice->contact->update(['email' => null]);
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->subMinute(), 'subject' => 's', 'message' => 'm',
        ]);

        $this->artisan('invoices:dispatch-scheduled-sends');

        $this->assertSame(InvoiceSend::STATUS_CANCELLED, $invoiceSend->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_the_kill_switch_stops_scheduled_dispatch(): void
    {
        Mail::fake();
        config(['invoicing.automated_sends_enabled' => false]);

        $invoice = $this->makeInvoice();
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->subMinute(), 'subject' => 's', 'message' => 'm',
        ]);

        $this->artisan('invoices:dispatch-scheduled-sends');

        $this->assertSame(InvoiceSend::STATUS_SCHEDULED, $invoiceSend->fresh()->status);
        Mail::assertNothingQueued();
    }
}
