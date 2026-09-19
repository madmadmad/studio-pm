<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_public_token_is_generated_when_an_invoice_is_created(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);

        $this->assertNotNull($invoice->public_token);
        $this->assertSame(40, strlen($invoice->public_token));
    }

    public function test_the_public_invoice_page_is_reachable_without_authentication(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $response = $this->get("/i/{$invoice->public_token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/InvoiceShow')
            ->where('invoice.id', $invoice->id)
            ->where('invoice.company.name', 'Alder & Finch Design')
            ->where('invoice.project.name', 'Brand refresh')
        );
    }

    public function test_an_unknown_token_returns_not_found(): void
    {
        $this->get('/i/does-not-exist')->assertNotFound();
    }
}
