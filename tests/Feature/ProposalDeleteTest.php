<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_proposal_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create(['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft']);

        $this->actingAs($user)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    }

    public function test_a_sent_proposal_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create([
            'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->actingAs($user)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    }

    public function test_an_accepted_proposal_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create([
            'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'accepted', 'accepted_at' => now(),
        ]);

        $this->actingAs($user)->deleteJson("/api/proposals/{$proposal->id}")->assertStatus(422);

        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
    }

    public function test_deleting_a_proposal_does_not_delete_its_linked_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft',
        ]);

        $this->actingAs($user)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_proposal_deletes_its_line_items(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create(['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft']);
        $proposal->items()->create(['description' => 'Design', 'quantity' => 1, 'rate' => 500]);

        $this->actingAs($user)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        $this->assertDatabaseCount('proposal_items', 0);
    }

    public function test_deleting_a_proposal_requires_authentication(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create(['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft']);

        $this->deleteJson("/api/proposals/{$proposal->id}")->assertUnauthorized();
    }
}
