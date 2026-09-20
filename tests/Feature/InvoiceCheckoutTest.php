<?php

namespace Tests\Feature;

use App\Listeners\MarkInvoicePaidFromStripeWebhook;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Checkout\Session;
use Tests\TestCase;

class InvoiceCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(string $status, bool $surcharge = true): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'status' => $status,
            'surcharge' => $surcharge,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_card_checkout_creates_a_stripe_session_and_stores_its_id(): void
    {
        $invoice = $this->makeInvoice('sent');

        $this->mock(StripeCheckoutService::class, function ($mock) {
            $mock->shouldReceive('createCardSessionFor')
                ->once()
                ->andReturn(Session::constructFrom(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/pay/cs_test_123']));
        });

        $response = $this->postJson("/api/invoices/{$invoice->public_token}/checkout", ['method' => 'card']);

        $response->assertOk();
        $response->assertJson(['url' => 'https://checkout.stripe.com/pay/cs_test_123']);
        $this->assertSame('cs_test_123', $invoice->fresh()->stripe_checkout_session_id);
    }

    public function test_ach_checkout_creates_a_stripe_session_regardless_of_the_surcharge_flag(): void
    {
        $invoice = $this->makeInvoice('sent', surcharge: false);

        $this->mock(StripeCheckoutService::class, function ($mock) {
            $mock->shouldReceive('createAchSessionFor')
                ->once()
                ->andReturn(Session::constructFrom(['id' => 'cs_test_ach', 'url' => 'https://checkout.stripe.com/pay/cs_test_ach']));
        });

        $response = $this->postJson("/api/invoices/{$invoice->public_token}/checkout", ['method' => 'ach']);

        $response->assertOk();
        $this->assertSame('cs_test_ach', $invoice->fresh()->stripe_checkout_session_id);
    }

    public function test_card_checkout_is_rejected_when_the_invoice_does_not_allow_it(): void
    {
        $invoice = $this->makeInvoice('sent', surcharge: false);

        $this->postJson("/api/invoices/{$invoice->public_token}/checkout", ['method' => 'card'])
            ->assertStatus(422);
    }

    public function test_an_invalid_method_is_rejected(): void
    {
        $invoice = $this->makeInvoice('sent');

        $this->postJson("/api/invoices/{$invoice->public_token}/checkout", ['method' => 'bitcoin'])
            ->assertStatus(422);
    }

    public function test_a_draft_invoice_cannot_be_checked_out(): void
    {
        $invoice = $this->makeInvoice('draft');

        $this->postJson("/api/invoices/{$invoice->public_token}/checkout", ['method' => 'card'])->assertStatus(422);
    }

    public function test_an_already_paid_invoice_cannot_be_checked_out_again(): void
    {
        $invoice = $this->makeInvoice('paid');

        $this->postJson("/api/invoices/{$invoice->public_token}/checkout", ['method' => 'card'])->assertStatus(422);
    }

    public function test_an_unknown_token_returns_not_found(): void
    {
        $this->postJson('/api/invoices/does-not-exist/checkout', ['method' => 'card'])->assertNotFound();
    }

    public function test_the_webhook_listener_marks_a_card_payment_paid_and_splits_base_from_surcharge(): void
    {
        $invoice = $this->makeInvoice('sent');
        $invoice->update(['stripe_checkout_session_id' => 'cs_test_456']);

        $event = new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_456',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_456',
                'metadata' => ['method' => 'card', 'base_amount' => '1000.00', 'surcharge_amount' => '30.00'],
            ]],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertCount(1, $invoice->payments);
        $payment = $invoice->payments->first();
        $this->assertSame('card', $payment->method);
        $this->assertEqualsWithDelta(1000.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $payment->surcharge_amount, 0.001);
        $this->assertSame('pi_test_456', $payment->stripe_payment_intent_id);

        // The surcharge never reaches bookkeeping's income figure for this invoice.
        $transaction = Transaction::where('invoice_id', $invoice->id)->first();
        $this->assertEqualsWithDelta(1000.0, (float) $transaction->amount, 0.001);
    }

    public function test_an_ach_sessions_completed_event_does_not_mark_paid_until_settled(): void
    {
        $invoice = $this->makeInvoice('sent');
        $invoice->update(['stripe_checkout_session_id' => 'cs_test_ach_1']);

        $event = new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_ach_1',
                'payment_status' => 'unpaid', // ACH still clearing
                'metadata' => ['method' => 'ach', 'base_amount' => '1000.00', 'surcharge_amount' => '0.00'],
            ]],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_an_ach_payment_is_marked_paid_on_the_async_success_event(): void
    {
        $invoice = $this->makeInvoice('sent');
        $invoice->update(['stripe_checkout_session_id' => 'cs_test_ach_2']);

        $event = new WebhookReceived([
            'type' => 'checkout.session.async_payment_succeeded',
            'data' => ['object' => [
                'id' => 'cs_test_ach_2',
                'metadata' => ['method' => 'ach', 'base_amount' => '1000.00', 'surcharge_amount' => '0.00'],
            ]],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $payment = $invoice->payments->first();
        $this->assertSame('ach', $payment->method);
        $this->assertEqualsWithDelta(0.0, (float) $payment->surcharge_amount, 0.001);
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
        $invoice->recordPayment('card', 1000, 30);

        $event = new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_999',
                'payment_status' => 'paid',
                'metadata' => ['method' => 'card', 'base_amount' => '1000.00', 'surcharge_amount' => '30.00'],
            ]],
        ]);

        (new MarkInvoicePaidFromStripeWebhook)->handle($event);

        $this->assertCount(1, $invoice->fresh()->payments);
    }
}
