<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDeleteTest extends TestCase
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
            'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_a_draft_invoice_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice('draft');

        $this->actingAs($user)->deleteJson("/api/invoices/{$invoice->id}")->assertNoContent();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id]);
    }

    public function test_a_sent_invoice_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice('sent');

        $this->actingAs($user)->deleteJson("/api/invoices/{$invoice->id}")->assertNoContent();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_a_paid_invoice_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice('sent');
        $invoice->recordPayment();

        $this->actingAs($user)->deleteJson("/api/invoices/{$invoice->id}")->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_deleting_an_invoice_frees_up_time_entries_billed_to_it(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice('draft');
        $item = $invoice->items()->first();
        $timeEntry = TimeEntry::create([
            'company_id' => $invoice->company_id,
            'user_id' => $user->id,
            'date' => now(),
            'hours' => 2,
            'billed' => true,
            'invoice_item_id' => $item->id,
        ]);

        $this->actingAs($user)->deleteJson("/api/invoices/{$invoice->id}")->assertNoContent();

        $timeEntry->refresh();
        $this->assertFalse($timeEntry->billed);
        $this->assertNull($timeEntry->invoice_item_id);
    }

    public function test_deleting_an_invoice_requires_authentication(): void
    {
        $invoice = $this->makeInvoice('draft');

        $this->deleteJson("/api/invoices/{$invoice->id}")->assertUnauthorized();
    }
}
