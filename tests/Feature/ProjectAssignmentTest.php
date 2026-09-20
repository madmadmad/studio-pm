<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectAndTeamMember(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $teamMember = User::factory()->teamMember()->create();

        return [$project, $teamMember];
    }

    public function test_a_manager_can_assign_a_team_member_to_a_project(): void
    {
        $manager = User::factory()->create();
        [$project, $teamMember] = $this->makeProjectAndTeamMember();

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->id}/assignments", ['user_id' => $teamMember->id])
            ->assertOk();

        $this->assertTrue($project->currentlyHasUser($teamMember));
    }

    public function test_a_team_member_cannot_assign_anyone_to_a_project(): void
    {
        [$project, $teamMember] = $this->makeProjectAndTeamMember();
        $other = User::factory()->teamMember()->create();

        $this->actingAs($teamMember)
            ->postJson("/api/projects/{$project->id}/assignments", ['user_id' => $other->id])
            ->assertForbidden();
    }

    public function test_unassigning_soft_removes_but_keeps_history(): void
    {
        $manager = User::factory()->create();
        [$project, $teamMember] = $this->makeProjectAndTeamMember();
        $project->users()->attach($teamMember->id, ['assigned_at' => now()]);

        $this->actingAs($manager)
            ->deleteJson("/api/projects/{$project->id}/assignments/{$teamMember->id}")
            ->assertNoContent();

        $this->assertFalse($project->currentlyHasUser($teamMember));
        $this->assertTrue($project->everHadUser($teamMember));
    }
}
