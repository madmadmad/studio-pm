<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function hosting(): InvoiceCategory
    {
        return InvoiceCategory::firstOrCreate(['name' => 'Hosting']);
    }

    private function invoice(Company $company, ?InvoiceCategory $category, string $issued, string $status, float $amount, ?int $projectId = null): Invoice
    {
        $invoice = $company->invoices()->create([
            'project_id' => $projectId, 'category_id' => $category?->id, 'status' => $status,
            'issued_on' => $issued, 'due_on' => $issued, 'payment_terms' => 'net_30',
        ]);
        $invoice->items()->create(['description' => 'Line', 'amount' => $amount, 'position' => 0]);

        return $invoice;
    }

    public function test_hosting_starts_as_a_category_and_a_hosting_invoice_needs_no_project(): void
    {
        $this->assertTrue(InvoiceCategory::where('name', 'Hosting')->exists());
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $invoice = $this->actingAs(User::factory()->create())->postJson("/api/companies/{$company->id}/invoices", [
            'category_id' => $this->hosting()->id,
            'items' => [['description' => 'Website hosting, 2026', 'amount' => 600]],
        ])->assertCreated()->json();

        $this->assertNull($invoice['project_id']);
        $this->assertSame('Hosting', $invoice['category']['name']);
    }

    public function test_an_edit_can_change_the_category_and_leaving_it_out_keeps_it(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $this->invoice($company, null, '2026-09-01', 'sent', 100);
        $manager = User::factory()->create();
        $line = ['id' => $invoice->items->first()->id, 'description' => 'Line', 'amount' => 100];

        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", ['category_id' => $this->hosting()->id, 'items' => [$line]])->assertOk();
        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", ['items' => [$line]])->assertOk();

        $this->assertSame($this->hosting()->id, $invoice->fresh()->category_id);
    }

    public function test_the_yearly_report_groups_issued_invoices_by_category(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $hosting = $this->hosting();
        $this->invoice($company, $hosting, '2026-02-01', 'paid', 600)->payments()->create(['method' => 'check', 'amount' => 600, 'surcharge_amount' => 0, 'paid_at' => now()]);
        $this->invoice($company, $hosting, '2026-08-01', 'sent', 300);
        $this->invoice($company, null, '2026-05-01', 'sent', 1000, $project->id);
        $this->invoice($company, $hosting, '2026-09-01', 'draft', 999);   // not issued yet
        $this->invoice($company, $hosting, '2025-12-15', 'paid', 600);    // last year

        $this->actingAs(User::factory()->create())->get('/bookkeeping/invoice-categories?year=2026')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Bookkeeping/InvoiceCategories')
                ->where('report.categories.0.name', 'Project work')
                ->where('report.categories.0.count', 1)
                ->where('report.categories.1.name', 'Hosting')
                ->where('report.categories.1.count', 2)
                ->where('report.categories.1.total', 900)
                ->where('report.categories.1.paid', 600)
                ->where('report.categories.1.outstanding', 300)
                ->where('report.totals.total', 1900));

        $csv = $this->get('/bookkeeping/invoice-categories.csv?year=2026')->assertDownload('invoices-by-category-2026.csv')->streamedContent();
        $this->assertStringContainsString('Category,Invoice,Client,Project,Issued,Status,Total,Outstanding', $csv);
        $this->assertSame(2, substr_count($csv, 'Hosting,'));
    }

    public function test_categories_are_managed_by_managers_and_project_work_is_reserved(): void
    {
        $manager = User::factory()->create();

        $created = $this->actingAs($manager)->postJson('/api/invoice-categories', ['name' => 'Maintenance'])->assertCreated()->json();
        $this->actingAs($manager)->postJson('/api/invoice-categories', ['name' => 'Project work'])->assertUnprocessable();
        $this->actingAs(User::factory()->teamMember()->create())->postJson('/api/invoice-categories', ['name' => 'Retainers'])->assertForbidden();

        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $this->invoice($company, InvoiceCategory::find($created['id']), '2026-09-01', 'sent', 100);
        $this->actingAs($manager)->deleteJson("/api/invoice-categories/{$created['id']}")->assertNoContent();

        $this->assertNull($invoice->fresh()->category_id); // back to project work
    }

    public function test_clients_see_the_category_in_the_portal(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Jo Park', 'email' => 'jo@example.com', 'is_primary' => true]);
        $contact->forceFill(['portal_invited_at' => now()])->save();
        $this->invoice($company, $this->hosting(), '2026-09-01', 'sent', 600);

        $this->actingAs($contact, 'client')->get('/portal/invoices')
            ->assertInertia(fn ($page) => $page->where('invoices.0.category.name', 'Hosting'));
    }
}
