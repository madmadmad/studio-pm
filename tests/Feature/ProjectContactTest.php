<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_can_be_created_with_a_contact(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co', 'is_primary' => true]);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/projects", [
            'name' => 'Brand refresh',
            'contact_id' => $contact->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', ['name' => 'Brand refresh', 'contact_id' => $contact->id]);
    }

    public function test_a_contact_from_a_different_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherContact = $companyB->contacts()->create(['name' => 'Tomas Marsh', 'email' => 'tomas@marshgrove.com']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$companyA->id}/projects", [
            'name' => 'Brand refresh',
            'contact_id' => $otherContact->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_projects_contact_can_be_changed(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $response = $this->actingAs($user)->patchJson("/api/projects/{$project->id}", [
            'contact_id' => $contact->id,
        ]);

        $response->assertOk();
        $this->assertSame($contact->id, $project->fresh()->contact_id);
    }

    public function test_a_projects_contact_can_be_cleared(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'contact_id' => $contact->id]);

        $this->actingAs($user)->patchJson("/api/projects/{$project->id}", ['contact_id' => null])->assertOk();

        $this->assertNull($project->fresh()->contact_id);
    }

    public function test_a_proposal_that_creates_a_new_project_carries_its_contact_along(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co', 'is_primary' => true]);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Brand refresh',
            'body' => '<p>Scope</p>',
            'contact_id' => $contact->id,
            'new_project_name' => 'Brand Refresh',
        ]);

        $response->assertCreated();
        $projectId = $response->json('project_id');
        $this->assertDatabaseHas('projects', ['id' => $projectId, 'contact_id' => $contact->id]);
    }
}
