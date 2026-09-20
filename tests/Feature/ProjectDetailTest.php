<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invoice_can_be_attributed_directly_to_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'project_id' => $project->id,
            'items' => [['description' => 'Monthly retainer', 'amount' => 2000]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoices', ['id' => $response->json('id'), 'project_id' => $project->id]);
    }

    public function test_a_project_id_from_another_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherProject = $companyB->projects()->create(['name' => 'Menu redesign']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$companyA->id}/invoices", [
            'project_id' => $otherProject->id,
            'items' => [['description' => 'Work', 'amount' => 500]],
        ]);

        $response->assertUnprocessable();
    }

    public function test_an_expense_can_be_logged_against_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $this->actingAs($user)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 45.50,
            'occurred_on' => now()->toDateString(),
            'project_id' => $project->id,
        ])->assertCreated();

        $this->assertSame(1, $project->fresh('transactions')->transactions->count());
    }

    public function test_notes_can_be_added_and_listed_for_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", [
            'body' => 'Kickoff call went well.',
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}/notes");
        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_a_blank_note_can_be_created_with_no_title_or_body(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", []);

        $response->assertCreated();
        $this->assertDatabaseHas('notes', ['project_id' => $project->id, 'title' => null, 'body' => null]);
    }

    public function test_a_note_title_and_body_can_be_updated_and_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $note = $project->notes()->create([]);

        $this->actingAs($user)->patchJson("/api/notes/{$note->id}", [
            'title' => 'Kickoff notes',
            'body' => '<p>Great call.</p>',
        ])->assertOk();

        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
            'title' => 'Kickoff notes',
            'body' => '<p>Great call.</p>',
        ]);

        $this->actingAs($user)->deleteJson("/api/notes/{$note->id}")->assertNoContent();
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }
}
