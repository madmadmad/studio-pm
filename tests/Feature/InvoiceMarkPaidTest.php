<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_a_time_entry_into_an_invoice_marks_it_billed_and_links_it(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Marsh Grove Bakery']);
        $timeEntry = TimeEntry::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'date' => now(),
            'hours' => 2,
            'note' => 'Menu photography direction',
        ]);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [
                ['description' => 'Menu photography direction', 'amount' => 190, 'time_entry_ids' => [$timeEntry->id]],
            ],
        ]);

        $response->assertCreated();
        $invoiceItemId = $response->json('items.0.id');

        $timeEntry->refresh();
        $this->assertTrue((bool) $timeEntry->billed);
        $this->assertSame($invoiceItemId, $timeEntry->invoice_item_id);
    }

    public function test_marking_an_invoice_paid_creates_a_payment_and_a_matching_income_transaction(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $invoice = $company->invoices()->create([
            'status' => 'sent',
            'surcharge' => true,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Brand refresh', 'amount' => 507.50]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/mark-paid");

        $response->assertOk();
        $response->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 507.5,
            'surcharge_amount' => 15.23,
        ]);

        $transaction = Transaction::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($transaction);
        $this->assertSame('income', $transaction->type);
        $this->assertEqualsWithDelta(522.73, (float) $transaction->amount, 0.001);
    }

    public function test_marking_paid_without_surcharge_records_the_subtotal_only(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Thistle & Rye Events']);

        $invoice = $company->invoices()->create([
            'status' => 'sent',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Event coordination', 'amount' => 400]);

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/mark-paid")->assertOk();

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 400,
            'surcharge_amount' => 0,
        ]);

        $transaction = Transaction::where('invoice_id', $invoice->id)->first();
        $this->assertEqualsWithDelta(400.0, (float) $transaction->amount, 0.001);
    }

    public function test_an_invoice_can_be_addressed_to_a_specific_billing_contact(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Accounts Payable', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'contact_id' => $contact->id,
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoices', ['id' => $response->json('id'), 'contact_id' => $contact->id]);
    }

    public function test_an_invoice_contact_from_a_different_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherContact = $companyB->contacts()->create(['name' => 'Tomas Marsh']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$companyA->id}/invoices", [
            'contact_id' => $otherContact->id,
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertUnprocessable();
    }
}
