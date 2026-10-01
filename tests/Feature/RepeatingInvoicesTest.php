<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceCategory;
use App\Models\InvoiceSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RepeatingInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private function hostingInvoice(): array
    {
        Bus::fake();
        $this->travelTo('2026-10-01 10:00:00');
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Jo Park', 'email' => 'jo@example.com', 'is_primary' => true, 'is_billing' => true]);
        $invoice = $company->invoices()->create([
            'category_id' => InvoiceCategory::firstOrCreate(['name' => 'Hosting'])->id,
            'contact_id' => $contact->id, 'status' => 'draft',
            'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'payment_terms' => 'net_30',
        ]);
        $invoice->items()->create(['description' => 'Monthly website hosting', 'amount' => 95, 'position' => 0]);

        return [$invoice, User::factory()->create()];
    }

    private function send(Invoice $invoice, User $manager, array $extra = [])
    {
        return $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email',
            'subject' => "Studio sent you invoice #{$invoice->invoice_number}.",
            'message' => 'Your hosting invoice for $95.00, due Oct 31, 2026.',
            'cc' => ['billing@example.com'],
            'schedule' => 'now',
            ...$extra,
        ]);
    }

    public function test_sending_with_repeat_starts_a_monthly_series(): void
    {
        [$invoice, $manager] = $this->hostingInvoice();

        $this->send($invoice, $manager, ['repeat' => 'monthly'])->assertOk()
            ->assertJsonPath('repeat', 'monthly');

        $invoice->refresh();
        $this->assertSame('2026-11-01', $invoice->next_repeat_on->toDateString());
        $this->assertSame(['billing@example.com'], $invoice->repeat_cc);
    }

    public function test_the_daily_job_makes_the_next_copy_and_schedules_its_email(): void
    {
        [$invoice, $manager] = $this->hostingInvoice();
        $this->send($invoice, $manager, ['repeat' => 'monthly']);

        // Nothing until the date comes.
        $this->artisan('invoices:create-repeats');
        $this->assertSame(1, Invoice::count());

        $this->travelTo('2026-11-01 06:00:00');
        $this->artisan('invoices:create-repeats');

        $copy = Invoice::where('repeated_from_id', $invoice->id)->sole();
        $this->assertSame('2026-11-01', $copy->issued_on->toDateString());
        $this->assertSame('2026-12-01', $copy->due_on->toDateString());
        $this->assertSame('draft', $copy->status);
        $this->assertSame($invoice->category_id, $copy->category_id);
        $this->assertSame(['Monthly website hosting'], $copy->items->pluck('description')->all());
        $this->assertSame('2026-12-01', $invoice->fresh()->next_repeat_on->toDateString());

        $send = InvoiceSend::where('invoice_id', $copy->id)->sole();
        $this->assertSame(InvoiceSend::STATUS_SCHEDULED, $send->status);
        // 9 AM Eastern that day (standard time again from Nov 1).
        $this->assertSame('2026-11-01 09:00', $send->scheduled_for->copy()->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame("Studio sent you invoice #{$copy->invoice_number}.", $send->subject);
        $this->assertSame('Your hosting invoice for $95.00, due Dec 1, 2026.', $send->message);
        $this->assertSame(['billing@example.com'], $send->cc);

        // Running again the same day makes nothing more.
        $this->artisan('invoices:create-repeats');
        $this->assertSame(2, Invoice::count());
    }

    public function test_a_yearly_series_and_stopping_one(): void
    {
        [$invoice, $manager] = $this->hostingInvoice();
        $this->send($invoice, $manager, ['repeat' => 'yearly']);
        $this->assertSame('2027-10-01', $invoice->fresh()->next_repeat_on->toDateString());

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/repeat/stop")->assertOk()->assertJsonPath('repeat', null);

        $this->travelTo('2027-10-01 06:00:00');
        $this->artisan('invoices:create-repeats');
        $this->assertSame(1, Invoice::count());
    }

    public function test_repeating_is_email_only(): void
    {
        [$invoice, $manager] = $this->hostingInvoice();

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/send", ['method' => 'link', 'repeat' => 'monthly'])
            ->assertStatus(422);
    }

    public function test_month_ends_do_not_spill_over(): void
    {
        $this->assertSame('2027-02-28', Invoice::nextRepeatDate('monthly', \Illuminate\Support\Carbon::parse('2027-01-31'))->toDateString());
    }
}
