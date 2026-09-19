<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_subtasks_are_appended_in_order(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Design homepage']);

        $first = $this->actingAs($user)->postJson("/api/tasks/{$task->id}/subtasks", ['title' => 'Wireframes'])->json();
        $second = $this->actingAs($user)->postJson("/api/tasks/{$task->id}/subtasks", ['title' => 'Mockups'])->json();

        $this->assertSame(0, $first['position']);
        $this->assertSame(1, $second['position']);
    }

    public function test_a_subtask_status_and_assignee_can_be_updated(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Design homepage']);
        $subtask = $task->subtasks()->create(['title' => 'Wireframes', 'position' => 0]);

        $response = $this->actingAs($user)->patchJson("/api/subtasks/{$subtask->id}", [
            'status' => 'done',
            'assignee' => 'Rosa Alder',
        ]);

        $response->assertOk();
        $this->assertSame('done', $subtask->fresh()->status);
        $this->assertSame('Rosa Alder', $subtask->fresh()->assignee);
    }

    public function test_deleting_a_task_deletes_its_subtasks(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Design homepage']);
        $subtask = $task->subtasks()->create(['title' => 'Wireframes', 'position' => 0]);

        $task->delete();

        $this->assertDatabaseMissing('subtasks', ['id' => $subtask->id]);
    }
}
