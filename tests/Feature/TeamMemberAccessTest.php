<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberAccessTest extends TestCase
{
    use RefreshDatabase;

    private function assignedProject(User $teamMember): Project
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $project->users()->attach($teamMember->id, ['assigned_at' => now()]);

        return $project;
    }

    public function test_a_team_member_only_sees_their_assigned_projects_in_the_index(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $mine = $this->assignedProject($teamMember);
        $company = $mine->company;
        $notMine = $company->projects()->create(['name' => 'Someone else\'s project']);

        $response = $this->actingAs($teamMember)->getJson("/api/companies/{$company->id}/projects");

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($notMine->id));
    }

    public function test_a_team_member_cannot_view_a_project_they_were_never_assigned_to(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Not yours']);

        $this->actingAs($teamMember)->getJson("/api/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_team_member_keeps_read_only_access_after_being_unassigned(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $project = $this->assignedProject($teamMember);
        $project->users()->updateExistingPivot($teamMember->id, ['unassigned_at' => now()]);

        $this->actingAs($teamMember)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->actingAs($teamMember)
            ->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_a_team_member_can_create_and_update_tasks_on_an_assigned_project(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $project = $this->assignedProject($teamMember);

        $response = $this->actingAs($teamMember)
            ->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Kickoff call']);
        $response->assertCreated();

        $this->actingAs($teamMember)
            ->patchJson("/api/tasks/{$response->json('id')}", ['status' => 'done'])
            ->assertOk();
    }

    public function test_a_team_member_cannot_create_tasks_on_a_project_they_are_not_assigned_to(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Not yours']);

        $this->actingAs($teamMember)
            ->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Kickoff call'])
            ->assertForbidden();
    }

    public function test_a_team_member_can_log_time_only_against_an_assigned_project(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $project = $this->assignedProject($teamMember);

        $this->actingAs($teamMember)->postJson('/api/time-entries', [
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'date' => now()->toDateString(),
            'hours' => 2,
        ])->assertCreated();
    }

    public function test_a_team_member_cannot_log_time_without_a_project(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $this->actingAs($teamMember)->postJson('/api/time-entries', [
            'company_id' => $company->id,
            'date' => now()->toDateString(),
            'hours' => 2,
        ])->assertUnprocessable();
    }

    public function test_a_team_member_cannot_edit_another_teammates_time_entry(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $otherMember = User::factory()->teamMember()->create();
        $project = $this->assignedProject($teamMember);
        $project->users()->attach($otherMember->id, ['assigned_at' => now()]);

        $entry = TimeEntry::create([
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'user_id' => $otherMember->id,
            'date' => now()->toDateString(),
            'hours' => 3,
        ]);

        $this->actingAs($teamMember)
            ->patchJson("/api/time-entries/{$entry->id}", ['hours' => 5])
            ->assertForbidden();
    }

    public function test_a_team_member_cannot_reach_firm_financials(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $this->actingAs($teamMember)->getJson('/api/companies')->assertForbidden();
        $this->actingAs($teamMember)->getJson("/api/companies/{$company->id}/invoices")->assertForbidden();
        $this->actingAs($teamMember)->getJson("/api/companies/{$company->id}/proposals")->assertForbidden();
        $this->actingAs($teamMember)->getJson('/api/transactions')->assertForbidden();
        $this->actingAs($teamMember)->getJson('/api/services')->assertForbidden();
    }

    public function test_a_manager_is_not_restricted_by_project_assignment(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Any project']);

        $this->actingAs($manager)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->actingAs($manager)->getJson('/api/companies')->assertOk();
    }
}
