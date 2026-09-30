<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeDraftInvoice(): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_a_draft_invoices_line_items_can_be_replaced(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();

        $response = $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [
                ['description' => 'Revised scope', 'amount' => 1200],
                ['description' => 'Extra revisions', 'amount' => 300],
            ],
        ]);

        $response->assertOk();
        $invoice->refresh();
        $this->assertCount(2, $invoice->items);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'description' => 'Revised scope', 'amount' => 1200]);
        $this->assertDatabaseMissing('invoice_items', ['description' => 'Design work']);
    }

    public function test_contact_surcharge_and_due_date_can_be_updated(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();
        $contact = $invoice->company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $response = $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'contact_id' => $contact->id,
            'surcharge' => true,
            'payment_terms' => 'custom',
            'due_on' => '2026-11-01',
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertOk();
        $invoice->refresh();
        $this->assertSame($contact->id, $invoice->contact_id);
        $this->assertTrue($invoice->surcharge);
        $this->assertSame('2026-11-01', $invoice->due_on->toDateString());
    }

    public function test_a_sent_invoice_can_be_edited(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();
        $invoice->update(['status' => 'sent', 'sent_at' => now()]);
        $item = $invoice->items()->first();

        $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['id' => $item->id, 'description' => 'Design work (revised)', 'amount' => 1100]],
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame('sent', $invoice->status);
        $this->assertDatabaseHas('invoice_items', ['id' => $item->id, 'description' => 'Design work (revised)', 'amount' => 1100]);
    }

    public function test_a_paid_invoice_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();
        $invoice->update(['status' => 'paid']);

        $response = $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['description' => 'Changed', 'amount' => 500]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('invoice_items', ['description' => 'Design work']);
    }

    public function test_editing_a_kept_line_keeps_its_billed_time_and_expenses(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();
        $invoice->update(['status' => 'sent', 'sent_at' => now()]);
        $item = $invoice->items()->first();
        $timeEntry = TimeEntry::create([
            'company_id' => $invoice->company_id,
            'user_id' => $user->id,
            'date' => now(),
            'hours' => 2,
            'billed' => true,
            'invoice_item_id' => $item->id,
        ]);
        $project = Project::create(['company_id' => $invoice->company_id, 'name' => 'Brand refresh']);
        $expense = Expense::create([
            'project_id' => $project->id,
            'name' => 'Stock photography',
            'amount' => 50,
            'date' => now(),
            'is_billable' => true,
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item->id,
            'billing_status' => 'billed',
        ]);

        // Fix the kept line's wording and add a new line.
        $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [
                ['id' => $item->id, 'description' => 'Design work, corrected', 'amount' => 1000],
                ['description' => 'Rush fee', 'amount' => 150],
            ],
        ])->assertOk();

        $this->assertCount(2, $invoice->fresh()->items);
        $timeEntry->refresh();
        $this->assertTrue($timeEntry->billed);
        $this->assertSame($item->id, $timeEntry->invoice_item_id);
        $expense->refresh();
        $this->assertSame('billed', $expense->billing_status);
        $this->assertSame($item->id, $expense->invoice_item_id);
    }

    public function test_a_line_id_from_another_invoice_is_rejected(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();
        $other = $this->makeDraftInvoice();
        $otherItem = $other->items()->first();

        $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['id' => $otherItem->id, 'description' => 'Hijacked', 'amount' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseHas('invoice_items', ['id' => $otherItem->id, 'description' => 'Design work']);
    }

    public function test_removing_an_item_frees_up_the_time_entries_billed_to_it(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeDraftInvoice();
        $item = $invoice->items()->first();
        $timeEntry = TimeEntry::create([
            'company_id' => $invoice->company_id,
            'user_id' => $user->id,
            'date' => now(),
            'hours' => 2,
            'billed' => true,
            'invoice_item_id' => $item->id,
        ]);

        $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['description' => 'Different work', 'amount' => 800]],
        ])->assertOk();

        $timeEntry->refresh();
        $this->assertFalse($timeEntry->billed);
        $this->assertNull($timeEntry->invoice_item_id);
    }

    public function test_updating_an_invoice_requires_authentication(): void
    {
        $invoice = $this->makeDraftInvoice();

        $this->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['description' => 'Changed', 'amount' => 500]],
        ])->assertUnauthorized();
    }
}
