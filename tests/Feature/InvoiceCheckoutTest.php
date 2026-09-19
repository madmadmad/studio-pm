<?php

namespace Tests\Feature;

use App\Listeners\MarkInvoicePaidFromStripeWebhook;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Checkout\Session;
use Tests\TestCase;

class InvoiceCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(string $status): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'status' => $status,
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_checkout_creates_a_stripe_session_and_stores_its_id(): void
    {
        $invoice = $this->makeInvoice('sent');

        $this->mock(StripeCheckoutService::class, function ($mock) {
            $mock->shouldReceive('createSessionFor')
                ->once()
                ->andReturn(Session::constructFrom(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/pay/cs_test_123']));
        });

        $response = $this->postJson("/api/invoices/{$invoice->public_token}/checkout");

        $response->assertOk();
        $response->assertJson(['url' => 'https://checkout.stripe.com/pay/cs_test_123']);
        $this->assertSame('cs_test_123', $invoice->fresh()->stripe_checkout_session_id);
    }

    public function test_a_draft_invoice_cannot_be_checked_out(): void
    {
        $invoice = $this->makeInvoice('draft');

        $this->postJson("/api/invoices/{$invoice->public_token}/checkout")->assertStatus(422);
    }

    public function test_an_already_paid_invoice_cannot_be_checked_out_again(): void
    {
        $invoice = $this->makeInvoice('paid');

        $this->postJson("/api/invoices/{$invoice->public_token}/checkout")->assertStatus(422);
    }

    public function test_an_unknown_token_returns_not_found(): void
    {
        $this->postJson('/api/invoices/does-not-exist/checkout')->assertNotFound();
    }

    public function test_the_webhook_listener_marks_the_matching_invoice_paid(): void
    {
        $invoice = $this->makeInvoice('sent');
        $invoice->update(['stripe_checkout_session_id' => 'cs_test_456']);

        $event = new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_456']],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertCount(1, $invoice->payments);
    }

    public function test_the_webhook_listener_ignores_unrelated_event_types(): void
    {
        $invoice = $this->makeInvoice('sent');
        $invoice->update(['stripe_checkout_session_id' => 'cs_test_789']);

        $event = new WebhookReceived([
            'type' => 'customer.updated',
            'data' => ['object' => ['id' => 'cs_test_789']],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_the_webhook_listener_is_idempotent_for_an_already_paid_invoice(): void
    {
        $invoice = $this->makeInvoice('sent');
        $invoice->update(['stripe_checkout_session_id' => 'cs_test_999']);
        $invoice->recordPayment();

        $event = new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_999']],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $this->assertCount(1, $invoice->fresh()->payments);
    }
}
