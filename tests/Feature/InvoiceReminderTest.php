<?php

namespace Tests\Feature;

use App\Mail\InvoiceEmail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceReminderTest extends TestCase
{
    use RefreshDatabase;

    // Due today is 2026-05-01 America/New_York for every invoice made here,
    // so each test just travels to the right offset from that date.
    private function makeSentInvoice(array $overrides = []): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'AP', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);
        $invoice = $company->invoices()->create(array_merge([
            'contact_id' => $contact->id, 'status' => 'sent', 'surcharge' => false,
            'issued_on' => '2026-04-01', 'due_on' => '2026-05-01', 'payment_terms' => 'net_30',
        ], $overrides));
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    private function travelToDueOffset(int $days): void
    {
        $this->travelTo(Carbon::parse('2026-05-01 09:00:00', 'America/New_York')->addDays($days));
    }

    public function test_a_reminder_fires_3_days_before_the_due_date(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(-3);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_minus_3']);
        Mail::assertQueued(InvoiceEmail::class);
    }

    public function test_a_reminder_fires_on_the_due_date(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(0);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_0']);
    }

    public function test_a_reminder_fires_7_days_overdue(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_plus_7']);
    }

    public function test_a_reminder_fires_14_days_overdue_and_repeats_every_14_days_after(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();

        $this->travelToDueOffset(14);
        $this->artisan('invoices:send-reminders');
        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_plus_14']);

        $this->travelToDueOffset(28);
        $this->artisan('invoices:send-reminders');
        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_plus_28']);
    }

    public function test_no_reminder_fires_on_a_day_with_no_matching_rule(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(1);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 0);
        Mail::assertNothingQueued();
    }

    public function test_overdue_reminders_stop_after_the_configured_cap(): void
    {
        config(['invoicing.max_overdue_reminders' => 2]);
        Mail::fake();
        $invoice = $this->makeSentInvoice();

        $this->travelToDueOffset(7);
        $this->artisan('invoices:send-reminders');
        $this->travelToDueOffset(14);
        $this->artisan('invoices:send-reminders');
        $this->travelToDueOffset(28);
        $this->artisan('invoices:send-reminders');

        // Cap of 2 -- due_plus_7 and due_plus_14 fire, due_plus_28 does not.
        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_plus_14']);
        $this->assertDatabaseMissing('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_plus_28']);
    }

    public function test_a_reminder_never_fires_twice_for_the_same_invoice_even_if_the_command_runs_twice(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');
        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 1);
    }

    public function test_a_reminder_never_fires_for_a_paid_invoice(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $invoice->recordPayment('check', $invoice->total());
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 0);
    }

    public function test_a_reminder_never_fires_for_a_draft_invoice(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice(['status' => 'draft']);
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 0);
    }

    public function test_reminders_respect_the_per_invoice_override_even_when_the_client_default_is_on(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice(['reminders_enabled' => false]);
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 0);
    }

    public function test_reminders_respect_the_client_level_override_when_off(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $invoice->company->update(['reminders_enabled' => false]);
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 0);
    }

    public function test_a_reminder_states_the_remaining_balance_after_a_partial_payment(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $invoice->payments()->create(['method' => 'check', 'amount' => 400, 'paid_at' => now()]);
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        Mail::assertQueued(InvoiceEmail::class, fn (InvoiceEmail $mail) => str_contains($mail->body, '600.00') && ! str_contains($mail->body, '1,000.00'));
    }

    public function test_the_kill_switch_stops_reminders(): void
    {
        config(['invoicing.automated_sends_enabled' => false]);
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(7);

        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseCount('invoice_sends', 0);
    }

    public function test_a_single_upcoming_reminder_can_be_skipped(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $invoice = $this->makeSentInvoice();

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/reminders/skip", ['rule' => 'due_plus_7'])
            ->assertCreated();

        $this->travelToDueOffset(7);
        $this->artisan('invoices:send-reminders');

        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'reminder_rule' => 'due_plus_7', 'status' => 'cancelled']);
        Mail::assertNothingQueued();
    }

    public function test_a_reminder_that_already_went_out_cannot_be_skipped(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $invoice = $this->makeSentInvoice();
        $this->travelToDueOffset(7);
        $this->artisan('invoices:send-reminders');

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/reminders/skip", ['rule' => 'due_plus_7'])
            ->assertStatus(422);
    }

    // With no earlier email send to copy CCs from, a reminder still reaches
    // the client's other billing contacts.
    public function test_a_reminder_ccs_the_other_billing_contacts(): void
    {
        Mail::fake();
        $invoice = $this->makeSentInvoice();
        $invoice->company->contacts()->create(['name' => 'Priya Sen', 'email' => 'priya@alderfinch.co', 'is_billing' => true]);
        $invoice->company->contacts()->create(['name' => 'Not Billing', 'email' => 'other@alderfinch.co']);
        $this->travelToDueOffset(0);

        $this->artisan('invoices:send-reminders');

        $send = $invoice->invoiceSends()->where('reminder_rule', 'due_0')->first();
        $this->assertSame(['priya@alderfinch.co'], $send->cc);
        Mail::assertQueued(InvoiceEmail::class, fn ($mail) => $mail->hasCc('priya@alderfinch.co') && ! $mail->hasCc('other@alderfinch.co'));
    }

    public function test_billing_cc_emails_skip_the_to_address_and_contacts_without_email(): void
    {
        $invoice = $this->makeSentInvoice();
        $invoice->company->contacts()->create(['name' => 'Priya Sen', 'email' => 'priya@alderfinch.co', 'is_billing' => true]);
        $invoice->company->contacts()->create(['name' => 'No Email', 'is_billing' => true]);

        $this->assertSame(['priya@alderfinch.co'], $invoice->fresh(['company.contacts', 'contact'])->billingCcEmails());
    }
}
