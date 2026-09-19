<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPoNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_po_number_can_be_set_on_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->patchJson("/api/projects/{$project->id}", [
            'po_number' => 'PO-4471',
        ]);

        $response->assertOk();
        $this->assertSame('PO-4471', $project->fresh()->po_number);
    }

    public function test_a_po_number_can_be_cleared(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'po_number' => 'PO-1']);

        $this->actingAs($user)->patchJson("/api/projects/{$project->id}", ['po_number' => null])->assertOk();

        $this->assertNull($project->fresh()->po_number);
    }

    public function test_a_projects_po_number_is_visible_on_the_public_invoice_page(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'po_number' => 'PO-4471']);
        $invoice = $company->invoices()->create([
            'project_id' => $project->id,
            'status' => 'sent',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $response = $this->get("/i/{$invoice->public_token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('invoice.project.po_number', 'PO-4471'));
    }
}
