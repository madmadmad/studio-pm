<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\CreateSubtaskTool;
use App\Models\Subtask;
use Illuminate\Testing\Fluent\AssertableJson;

class CreateSubtaskToolTest extends McpTestCase
{
    public function test_it_adds_subtasks_to_the_end_of_a_tasks_list(): void
    {
        $task = $this->mine->tasks()->create(['title' => 'Launch']);
        $task->subtasks()->create(['title' => 'Already there', 'position' => 0]);

        $this->as($this->sam)->tool(CreateSubtaskTool::class, [
            'task_id' => $task->id,
            'subtasks' => [['title' => 'Test forms'], ['title' => 'Point DNS', 'assignee' => 'Sam']],
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('created', 2)
                ->where('task.subtasks', fn ($subtasks) => collect($subtasks)->pluck('title')->all() === ['Already there', 'Test forms', 'Point DNS'])
                ->where('task.subtasks.2.assignee', 'Sam')
                ->where('task.subtasks.1.status', 'todo')
                ->etc());

        $this->assertSame([0, 1, 2], $task->subtasks()->pluck('position')->all());
    }

    public function test_a_task_on_a_project_you_are_not_on_is_refused(): void
    {
        $task = $this->theirs->tasks()->create(['title' => 'Secret']);

        $this->as($this->sam)->tool(CreateSubtaskTool::class, ['task_id' => $task->id, 'subtasks' => [['title' => 'Sneaky']]])
            ->assertHasErrors(['This action is unauthorized.']);

        $this->assertSame(0, Subtask::count());
    }

    public function test_each_subtask_needs_a_title(): void
    {
        $task = $this->mine->tasks()->create(['title' => 'Launch']);

        $this->as($this->sam)->tool(CreateSubtaskTool::class, ['task_id' => $task->id, 'subtasks' => [['assignee' => 'Sam']]])
            ->assertHasErrors(['title']);
        $this->as($this->sam)->tool(CreateSubtaskTool::class, ['task_id' => $task->id, 'subtasks' => []])
            ->assertHasErrors(['subtasks']);
    }
}
