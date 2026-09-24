<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// GET /api/invoices/{invoice} -- the invoice detail the project page's
// invoice drawer loads, matching what the standalone invoice page gets.
class InvoiceDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_a_manager_gets_the_full_invoice_detail(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->actingAs(User::factory()->create())->getJson("/api/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('invoice.id', $invoice->id)
            ->assertJsonPath('invoice.items.0.description', 'Design work')
            ->assertJsonPath('invoice.company.name', 'Alder & Finch Design')
            ->assertJsonStructure([
                'invoice' => ['payments', 'invoice_sends', 'send_blocking_issues', 'remaining_balance', 'public_url'],
                'studio',
                'invoicingDefaults' => ['emailTemplate', 'emailSubjectTemplate'],
            ]);
    }

    public function test_a_team_member_cannot_read_invoice_detail(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs(User::factory()->teamMember()->create())
            ->getJson("/api/invoices/{$invoice->id}")
            ->assertForbidden();
    }
}
