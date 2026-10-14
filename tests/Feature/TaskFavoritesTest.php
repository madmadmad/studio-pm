<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskFavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_starring_a_task_is_personal_and_shows_on_the_project_page(): void
    {
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $task = $project->tasks()->create(['title' => 'Homepage comps']);
        $project->tasks()->create(['title' => 'Wireframes']);
        $me = User::factory()->create();
        $colleague = User::factory()->create();

        $this->actingAs($me)->postJson("/api/tasks/{$task->id}/favorite")->assertOk()->assertJson(['is_favorite' => true]);
        // Starring twice is harmless.
        $this->actingAs($me)->postJson("/api/tasks/{$task->id}/favorite")->assertOk();

        $this->actingAs($me)->get("/projects/{$project->id}")->assertInertia(fn ($page) => $page
            ->where('project.tasks.0.title', 'Homepage comps')->where('project.tasks.0.is_favorite', true)
            ->where('project.tasks.1.is_favorite', false));
        $this->actingAs($colleague)->get("/projects/{$project->id}")->assertInertia(fn ($page) => $page
            ->where('project.tasks.0.is_favorite', false));

        $this->actingAs($me)->deleteJson("/api/tasks/{$task->id}/favorite")->assertOk()->assertJson(['is_favorite' => false]);
        $this->assertSame(0, $me->favoriteTasks()->count());
    }

    public function test_team_members_can_only_star_tasks_on_projects_they_can_see(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $mine = $company->projects()->create(['name' => 'Brand refresh']);
        $other = $company->projects()->create(['name' => 'Website']);
        $teammate = User::factory()->teamMember()->create();
        $mine->users()->attach($teammate->id, ['assigned_at' => now()]);

        $this->actingAs($teammate)->postJson('/api/tasks/'.$mine->tasks()->create(['title' => 'A'])->id.'/favorite')->assertOk();
        $this->actingAs($teammate)->postJson('/api/tasks/'.$other->tasks()->create(['title' => 'B'])->id.'/favorite')->assertForbidden();
    }

    public function test_the_profile_lists_starred_tasks_open_ones_first_by_due_date(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $teammate = User::factory()->teamMember()->create();
        $project->users()->attach($teammate->id, ['assigned_at' => now()]);

        $done = $project->tasks()->create(['title' => 'Kickoff', 'status' => 'done', 'due_date' => '2026-10-01']);
        $undated = $project->tasks()->create(['title' => 'Style guide']);
        $later = $project->tasks()->create(['title' => 'Comps', 'due_date' => '2026-10-30']);
        $sooner = $project->tasks()->create(['title' => 'Wireframes', 'due_date' => '2026-10-15']);
        $project->tasks()->create(['title' => 'Not starred', 'due_date' => '2026-10-02']);
        $teammate->favoriteTasks()->attach([$done->id, $undated->id, $later->id, $sooner->id]);

        // A star on a project they're not on doesn't show.
        $hidden = $company->projects()->create(['name' => 'Website'])->tasks()->create(['title' => 'Elsewhere']);
        $teammate->favoriteTasks()->attach($hidden->id);

        $this->actingAs($teammate)->get('/profile')->assertInertia(fn ($page) => $page
            ->has('starredTasks', 4)
            ->where('starredTasks.0.title', 'Wireframes')
            ->where('starredTasks.0.project.name', 'Brand refresh')
            ->where('starredTasks.0.project.company.name', 'Alder & Finch Design')
            ->where('starredTasks.0.can_edit', true)
            ->where('starredTasks.1.title', 'Comps')
            ->where('starredTasks.2.title', 'Style guide')
            ->where('starredTasks.3.title', 'Kickoff'));
    }

    public function test_a_deleted_task_drops_its_stars(): void
    {
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Homepage comps']);
        $me = User::factory()->create();
        $me->favoriteTasks()->attach($task->id);

        $this->actingAs($me)->deleteJson("/api/tasks/{$task->id}")->assertNoContent();
        $this->assertSame(0, $me->favoriteTasks()->count());
    }
}
