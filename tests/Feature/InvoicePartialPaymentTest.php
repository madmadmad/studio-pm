<?php

namespace Tests\Feature;

use App\Listeners\MarkInvoicePaidFromStripeWebhook;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

// Part of an invoice paid (CPA review, Oct 2026): the invoice stays open
// for its balance, each payment carries its share of the sales tax, and
// the last one marks it paid.
class InvoicePartialPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        // $1,000 of design and $200 of printing taxed at 7.75%: $1,215.50.
        $this->invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => true, 'tax_name' => 'Ohio sales tax', 'tax_rate' => 7.75,
            'issued_on' => '2026-10-01', 'due_on' => '2026-10-31',
        ]);
        $this->invoice->items()->create(['description' => 'Design', 'amount' => 1000]);
        $this->invoice->items()->create(['description' => 'Printing', 'amount' => 200, 'taxable' => true]);
    }

    private function pay(array $fields)
    {
        return $this->actingAs($this->manager)->postJson("/api/invoices/{$this->invoice->id}/mark-paid", ['method' => 'check', ...$fields]);
    }

    public function test_part_of_an_invoice_is_paid_then_the_rest(): void
    {
        $this->pay(['amount' => '500.00', 'paid_on' => '2026-10-20'])->assertOk()->assertJsonPath('status', 'sent');

        $this->invoice->refresh();
        $this->assertSame(715.5, $this->invoice->remainingBalance());
        $this->assertSame(21.47, $this->invoice->cardSurchargeAmount(), 'the card fee is on the balance');
        // Its share of the tax: $15.50 × 500 / 1,215.50.
        $this->assertEqualsWithDelta(6.38, (float) Transaction::sole()->tax_amount, 0.001);
        $this->assertSame(-638, Account::forKey('sales_tax_payable')->netDebitCents());
        $this->assertSame('2026-10-20', JournalEntry::sole()->entry_date->toDateString(), 'posted on the day it came in');

        // With no amount, the rest of the balance.
        $this->pay([])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame(0.0, $this->invoice->fresh()->remainingBalance());
        $this->assertSame(-1550, Account::forKey('sales_tax_payable')->netDebitCents(), 'all the tax, once paid in full');

        $this->pay([])->assertUnprocessable()->assertJsonPath('message', 'This invoice is already paid in full.');
    }

    public function test_more_than_the_balance_or_a_future_date_is_refused(): void
    {
        $this->pay(['amount' => '1215.51'])->assertUnprocessable()->assertJsonPath('message', 'That’s more than the balance due ($1,215.50).');
        $this->pay(['amount' => '100', 'paid_on' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('paid_on');

        $this->assertSame(0, $this->invoice->payments()->count());
    }

    public function test_a_partly_paid_invoice_cannot_be_edited_or_deleted(): void
    {
        $this->pay(['amount' => '100'])->assertOk();

        $this->actingAs($this->manager)->putJson("/api/invoices/{$this->invoice->id}", ['items' => [['description' => 'Design', 'amount' => 900]]])
            ->assertUnprocessable()->assertJsonPath('message', 'This invoice has a payment recorded, so it can’t be edited.');
        $this->actingAs($this->manager)->deleteJson("/api/invoices/{$this->invoice->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'This invoice has payments recorded, so it can’t be deleted.');
    }

    public function test_the_client_pays_the_balance_by_card(): void
    {
        $this->pay(['amount' => '500'])->assertOk();
        $this->invoice->update(['stripe_checkout_session_id' => 'cs_test_balance']);

        (new MarkInvoicePaidFromStripeWebhook)->handle(new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_balance', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_balance',
                'metadata' => ['method' => 'card', 'base_amount' => '715.50', 'surcharge_amount' => '21.47'],
            ]],
        ]));

        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame(2, $this->invoice->payments()->count());
    }

    public function test_the_public_page_shows_the_balance_due(): void
    {
        $this->pay(['amount' => '500'])->assertOk();

        $this->get("/i/{$this->invoice->fresh()->public_token}")
            ->assertInertia(fn ($page) => $page->where('invoice.remaining_balance', 715.5)->has('invoice.payments', 1));
    }
}
