<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_project_defaults_to_leads(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $this->assertSame('leads', $project->fresh()->status);
    }

    public function test_sending_a_proposal_moves_a_lead_project_to_estimated(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft',
        ]);

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send")->assertOk();

        $this->assertSame('estimated', $project->fresh()->status);
    }

    public function test_sending_a_proposal_moves_its_project_to_estimated(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'inactive']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft',
        ]);

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send")->assertOk();

        $this->assertSame('estimated', $project->fresh()->status);
    }

    public function test_accepting_a_proposal_moves_its_project_to_active(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'estimated']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Brand refresh', 'body' => '<p>Scope</p>',
            'estimate_amount' => 1000, 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_sending_a_proposal_does_not_downgrade_a_completed_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'completed']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Phase 2', 'body' => '<p>Scope</p>', 'status' => 'draft',
        ]);

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send")->assertOk();

        $this->assertSame('completed', $project->fresh()->status);
    }

    public function test_accepting_a_proposal_does_not_reactivate_an_archived_project(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'archived']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Phase 2', 'body' => '<p>Scope</p>',
            'estimate_amount' => 1000, 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        $this->assertSame('archived', $project->fresh()->status);
    }

    public function test_a_project_can_be_set_to_each_new_status(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        foreach (['leads', 'estimated', 'active', 'inactive', 'completed', 'archived'] as $status) {
            $this->actingAs($user)->patchJson("/api/projects/{$project->id}", ['status' => $status])
                ->assertOk();
            $this->assertSame($status, $project->fresh()->status);
        }
    }

    public function test_the_old_on_hold_status_is_no_longer_valid(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $this->actingAs($user)->patchJson("/api/projects/{$project->id}", ['status' => 'on_hold'])
            ->assertUnprocessable();
    }

    public function test_archived_projects_are_excluded_from_the_main_projects_page(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $active = $company->projects()->create(['name' => 'Active project', 'status' => 'active']);
        $company->projects()->create(['name' => 'Archived project', 'status' => 'archived']);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('projects', 1)
            ->where('projects.0.id', $active->id)
        );
    }

    public function test_the_archived_page_shows_only_archived_projects(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $active = $company->projects()->create(['name' => 'Active project', 'status' => 'active']);
        $archived = $company->projects()->create(['name' => 'Archived project', 'status' => 'archived']);

        $response = $this->actingAs($user)->get('/projects/archived');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('archivedView', true)
            ->has('projects', 1)
            ->where('projects.0.id', $archived->id)
        );
    }
}
