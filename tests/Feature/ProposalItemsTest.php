<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_proposal_can_be_addressed_to_a_specific_contact(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'contact_id' => $contact->id,
            'new_project_name' => 'Marketing Support',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('proposals', [
            'id' => $response->json('id'),
            'contact_id' => $contact->id,
        ]);
    }

    public function test_a_contact_from_a_different_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherContact = $companyB->contacts()->create(['name' => 'Tomas Marsh', 'email' => 'tomas@marshgrove.com']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$companyA->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'contact_id' => $otherContact->id,
            'new_project_name' => 'Marketing Support',
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_project_from_a_different_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherProject = $companyB->projects()->create(['name' => 'Menu redesign']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$companyA->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'project_id' => $otherProject->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_proposal_requires_a_project_to_be_picked_or_created(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_proposal_can_be_created_against_an_existing_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'project_id' => $project->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('proposals', [
            'id' => $response->json('id'),
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_a_proposal_can_create_a_new_project_by_name(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'new_project_name' => 'Q4 Rebrand',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', ['company_id' => $company->id, 'name' => 'Q4 Rebrand']);
        $project = $company->projects()->where('name', 'Q4 Rebrand')->firstOrFail();
        $this->assertDatabaseHas('proposals', [
            'id' => $response->json('id'),
            'project_id' => $project->id,
        ]);
    }

    public function test_creating_a_proposal_with_line_items_computes_the_estimate_from_them(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'new_project_name' => 'Marketing Support',
            'items' => [
                ['description' => 'Production Art (5 decks)', 'quantity' => 15, 'rate' => 130],
                ['description' => 'Development', 'quantity' => 4, 'rate' => 130],
                ['description' => 'Project Management', 'quantity' => 2, 'rate' => 130],
            ],
        ]);

        $response->assertCreated();
        // (15 + 4 + 2) * 130 = 2730
        $response->assertJsonPath('estimate_amount', 2730);
        $this->assertCount(3, $response->json('items'));
    }

    public function test_a_manual_estimate_amount_is_kept_when_no_items_are_given(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Flat quote',
            'body' => '<p>Scope</p>',
            'new_project_name' => 'Flat quote project',
            'estimate_amount' => 500,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('estimate_amount', 500);
        $this->assertCount(0, $response->json('items'));
    }

    public function test_a_proposal_can_be_edited_after_creation(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $created = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Original scope</p>',
            'new_project_name' => 'Marketing Support',
            'items' => [
                ['description' => 'Design', 'quantity' => 10, 'rate' => 100],
            ],
        ]);
        $proposalId = $created->json('id');

        $response = $this->actingAs($user)->patchJson("/api/proposals/{$proposalId}", [
            'title' => 'Marketing Support (revised)',
            'body' => '<p>Revised scope</p>',
            'items' => [
                ['description' => 'Design', 'quantity' => 10, 'rate' => 100],
                ['description' => 'Extra revisions', 'quantity' => 5, 'rate' => 100],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('title', 'Marketing Support (revised)');
        // (10 + 5) * 100 = 1500
        $response->assertJsonPath('estimate_amount', 1500);
        $this->assertCount(2, $response->json('items'));

        $this->assertDatabaseHas('proposals', [
            'id' => $proposalId,
            'title' => 'Marketing Support (revised)',
            'body' => '<p>Revised scope</p>',
        ]);
        $this->assertDatabaseCount('proposal_items', 2);
    }

    public function test_editing_a_proposal_to_remove_items_falls_back_to_a_manual_estimate(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $created = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'new_project_name' => 'Marketing Support',
            'items' => [
                ['description' => 'Design', 'quantity' => 10, 'rate' => 100],
            ],
        ]);
        $proposalId = $created->json('id');

        $response = $this->actingAs($user)->patchJson("/api/proposals/{$proposalId}", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'estimate_amount' => 750,
        ]);

        $response->assertOk();
        $response->assertJsonPath('estimate_amount', 750);
        $this->assertCount(0, $response->json('items'));
        $this->assertDatabaseCount('proposal_items', 0);
    }
}
