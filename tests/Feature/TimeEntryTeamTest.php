<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Time is logged inside a project, against one of its team.
class TimeEntryTeamTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);
    }

    private function log(User $as, Project $project, array $extra = [])
    {
        return $this->actingAs($as)->postJson('/api/time-entries', [
            'project_id' => $project->id, 'date' => '2026-10-01', 'hours' => 2, ...$extra,
        ]);
    }

    public function test_every_entry_needs_a_project_even_for_a_manager(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)->postJson('/api/time-entries', ['date' => '2026-10-01', 'hours' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_the_client_comes_from_the_project(): void
    {
        $manager = User::factory()->create();
        $project = $this->project();

        $entry = $this->log($manager, $project)->assertCreated()->json();

        $this->assertSame($project->company_id, $entry['company_id']);
    }

    public function test_an_entry_is_the_signed_in_persons_time_by_default(): void
    {
        $member = User::factory()->teamMember()->create();
        $project = $this->project();
        $project->users()->attach($member->id, ['assigned_at' => now()]);

        $entry = $this->log($member, $project)->assertCreated()->json();

        $this->assertSame($member->id, $entry['user_id']);
        $this->assertSame($member->name, $entry['user']['name']);
    }

    public function test_a_manager_can_log_time_for_someone_on_the_team(): void
    {
        $manager = User::factory()->create();
        $member = User::factory()->teamMember()->create();
        $project = $this->project();
        $project->users()->attach($member->id, ['assigned_at' => now()]);

        $this->log($manager, $project, ['user_id' => $member->id])->assertCreated()->assertJsonPath('user_id', $member->id);
    }

    public function test_a_manager_cannot_log_time_for_someone_off_the_team(): void
    {
        $manager = User::factory()->create();
        $outsider = User::factory()->teamMember()->create();

        $this->log($manager, $this->project(), ['user_id' => $outsider->id])->assertUnprocessable();
    }

    public function test_a_team_member_cannot_log_time_for_someone_else(): void
    {
        $member = User::factory()->teamMember()->create();
        $colleague = User::factory()->teamMember()->create();
        $project = $this->project();
        $project->users()->attach([$member->id => ['assigned_at' => now()], $colleague->id => ['assigned_at' => now()]]);

        $this->log($member, $project, ['user_id' => $colleague->id])->assertForbidden();
    }

    public function test_a_manager_can_reassign_an_entry_to_a_teammate(): void
    {
        $manager = User::factory()->create();
        $member = User::factory()->teamMember()->create();
        $project = $this->project();
        $project->users()->attach($member->id, ['assigned_at' => now()]);
        $entry = TimeEntry::create(['company_id' => $project->company_id, 'project_id' => $project->id, 'user_id' => $manager->id, 'date' => '2026-10-01', 'hours' => 2]);

        $this->actingAs($manager)->patchJson("/api/time-entries/{$entry->id}", ['user_id' => $member->id])->assertOk();

        $this->assertSame($member->id, $entry->fresh()->user_id);
    }

    public function test_a_task_must_belong_to_the_entrys_project(): void
    {
        $manager = User::factory()->create();
        $project = $this->project();
        $otherTask = $this->project()->tasks()->create(['title' => 'Elsewhere']);

        $this->log($manager, $project, ['task_id' => $otherTask->id])->assertUnprocessable();
    }
}
