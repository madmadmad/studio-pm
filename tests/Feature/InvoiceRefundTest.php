<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Refunds (CPA review, Oct 2026): recorded once the money's gone back,
// taken back off the revenue it was booked to; and a super admin undoing
// a payment or refund recorded by mistake.
class InvoiceRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        // $1,000 of design and $200 of printing taxed at 7.75%: $1,215.50.
        $this->invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => true, 'tax_name' => 'Ohio sales tax', 'tax_rate' => 7.75,
            'issued_on' => '2026-10-01', 'due_on' => '2026-10-31',
        ]);
        $this->invoice->items()->create(['description' => 'Design', 'amount' => 1000]);
        $this->invoice->items()->create(['description' => 'Printing', 'amount' => 200, 'taxable' => true]);
    }

    private function balance(string $key): int
    {
        return Account::forKey($key)->netDebitCents();
    }

    private function refund(array $fields)
    {
        return $this->actingAs($this->admin)->postJson("/api/invoices/{$this->invoice->id}/refunds", ['method' => 'check', ...$fields]);
    }

    public function test_a_refund_comes_back_off_the_revenue_and_the_invoice_stays_paid(): void
    {
        $this->invoice->recordPayment('check', 1215.50);
        $this->assertSame(-120000, $this->balance('service_revenue'));

        $this->refund(['amount' => '607.75', 'refunded_on' => '2026-10-20', 'reason' => 'Printing reprint'])
            ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('refundable_amount', 607.75);

        // Half back off the revenue and the tax, out of checking.
        $this->assertSame(-60000, $this->balance('service_revenue'));
        $this->assertSame(-775, $this->balance('sales_tax_payable'));
        $this->assertSame(60775, $this->balance('checking'));
        $income = Transaction::whereNotNull('refund_id')->sole();
        $this->assertEqualsWithDelta(-607.75, (float) $income->amount, 0.001);
        $this->assertEqualsWithDelta(-7.75, (float) $income->tax_amount, 0.001);

        $this->refund(['amount' => '607.76'])->assertUnprocessable()->assertJsonPath('message', 'That’s more than was paid ($607.75).');
    }

    public function test_a_full_card_refund_gives_back_the_card_fee_and_a_partial_one_does_not(): void
    {
        $this->invoice->recordPayment('card', 1215.50, 36.47, 'pi_test');

        $this->refund(['method' => 'card', 'amount' => '100'])->assertOk();
        $this->assertSame(0.0, (float) $this->invoice->refunds()->first()->surcharge_amount);

        $this->refund(['method' => 'card', 'amount' => '1115.50'])->assertOk();
        $this->assertEqualsWithDelta(36.47, (float) $this->invoice->refunds()->latest('id')->first()->surcharge_amount, 0.001);
        // Everything that came in through Stripe went back out of it.
        $this->assertSame(0, $this->balance('stripe_clearing'));
        $this->assertSame(0, $this->balance('surcharge_income'));
        $this->assertSame(0, $this->balance('service_revenue'));
    }

    public function test_a_super_admin_undoes_a_payment_recorded_by_mistake(): void
    {
        $this->invoice->recordPayment('check', 1215.50);
        $payment = $this->invoice->payments()->sole();

        $staff = User::factory()->teamMember()->create();
        $staff->forceFill(['permissions' => ['invoices']])->save();
        $this->actingAs($staff)->deleteJson("/api/invoices/{$this->invoice->id}/payments/{$payment->id}")->assertForbidden();

        $this->actingAs($this->admin)->deleteJson("/api/invoices/{$this->invoice->id}/payments/{$payment->id}")
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->assertSame(0, $this->balance('checking'));
        $this->assertSame(0, $this->balance('service_revenue'));
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_payment_with_refunds_against_it_is_undone_only_after_them(): void
    {
        $this->invoice->recordPayment('check', 1215.50);
        $this->refund(['amount' => '100'])->assertOk();
        $payment = $this->invoice->payments()->sole();
        $refund = $this->invoice->refunds()->sole();

        $this->actingAs($this->admin)->deleteJson("/api/invoices/{$this->invoice->id}/payments/{$payment->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'Undo the refunds on this invoice first.');

        $this->actingAs($this->admin)->deleteJson("/api/invoices/{$this->invoice->id}/refunds/{$refund->id}")->assertOk();
        $this->assertSame(-120000, $this->balance('service_revenue'), 'the refund is gone from the books');
        $this->actingAs($this->admin)->deleteJson("/api/invoices/{$this->invoice->id}/payments/{$payment->id}")->assertOk();
    }

    public function test_the_client_sees_the_refund_but_not_its_reason(): void
    {
        $this->invoice->recordPayment('check', 1215.50);
        $this->refund(['amount' => '100', 'reason' => 'Unhappy with the printing'])->assertOk();

        $this->get("/i/{$this->invoice->fresh()->public_token}")
            ->assertInertia(fn ($page) => $page->has('invoice.refunds', 1)->missing('invoice.refunds.0.reason'));
    }
}
