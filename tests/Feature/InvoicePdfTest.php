<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pdf_can_be_downloaded_for_an_invoice(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'po_number' => 'PO-1234']);
        $invoice = $company->invoices()->create([
            'project_id' => $project->id,
            'status' => 'sent',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'details' => 'Homepage and about page.', 'amount' => 1000]);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_downloading_an_invoice_pdf_requires_authentication(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);

        $response = $this->get("/invoices/{$invoice->id}/pdf");

        $response->assertRedirect('/login');
    }

    public function test_a_client_can_download_the_pdf_from_the_public_invoice_link(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $response = $this->get("/i/{$invoice->public_token}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString("invoice-{$invoice->invoice_number}.pdf", $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_public_invoice_pdf_needs_a_valid_token(): void
    {
        $this->get('/i/not-a-real-token/pdf')->assertNotFound();
    }
}
