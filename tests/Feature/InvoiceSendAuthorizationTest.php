<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSendAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'AP', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);
        $invoice = $company->invoices()->create([
            'contact_id' => $contact->id, 'status' => 'draft', 'surcharge' => false,
            'issued_on' => now(), 'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_a_team_member_cannot_send_an_invoice(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $invoice = $this->makeInvoice();

        $this->actingAs($teamMember)->postJson("/api/invoices/{$invoice->id}/send", ['method' => 'link'])
            ->assertForbidden();
    }

    public function test_a_team_member_cannot_regenerate_the_token(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $invoice = $this->makeInvoice();

        $this->actingAs($teamMember)->postJson("/api/invoices/{$invoice->id}/regenerate-token")
            ->assertForbidden();
    }

    public function test_a_team_member_cannot_cancel_reschedule_or_send_now_a_scheduled_send(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $invoice = $this->makeInvoice();
        $invoiceSend = $invoice->invoiceSends()->create([
            'type' => InvoiceSend::TYPE_EMAIL, 'status' => InvoiceSend::STATUS_SCHEDULED,
            'scheduled_for' => now()->addHour(), 'subject' => 's', 'message' => 'm',
        ]);

        $this->actingAs($teamMember)->postJson("/api/invoices/{$invoice->id}/sends/{$invoiceSend->id}/cancel")->assertForbidden();
        $this->actingAs($teamMember)->postJson("/api/invoices/{$invoice->id}/sends/{$invoiceSend->id}/reschedule", ['scheduled_for' => now()->addDay()->toIso8601String()])->assertForbidden();
        $this->actingAs($teamMember)->postJson("/api/invoices/{$invoice->id}/sends/{$invoiceSend->id}/send-now")->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_send_an_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->postJson("/api/invoices/{$invoice->id}/send", ['method' => 'link'])->assertUnauthorized();
    }

    public function test_manual_sends_are_rate_limited_to_one_per_invoice_per_minute(): void
    {
        $user = User::factory()->create();
        $invoiceA = $this->makeInvoice();
        $invoiceB = $this->makeInvoice();

        $this->actingAs($user)->postJson("/api/invoices/{$invoiceA->id}/send", ['method' => 'link'])->assertOk();
        // Same invoice again within the same minute -- throttled.
        $this->actingAs($user)->postJson("/api/invoices/{$invoiceA->id}/send", ['method' => 'link'])->assertStatus(429);
        // A different invoice is unaffected by invoiceA's limiter.
        $this->actingAs($user)->postJson("/api/invoices/{$invoiceB->id}/send", ['method' => 'link'])->assertOk();
    }
}
