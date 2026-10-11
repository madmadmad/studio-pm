<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\UpdateTaskTool;
use Illuminate\Testing\Fluent\AssertableJson;

class UpdateTaskToolTest extends McpTestCase
{
    public function test_it_changes_only_the_fields_given(): void
    {
        $task = $this->mine->tasks()->create(['title' => 'Logo', 'assignee' => 'Sam', 'due_date' => '2026-10-20']);

        $this->as($this->sam)->tool(UpdateTaskTool::class, ['task_id' => $task->id, 'status' => 'done', 'due_date' => null])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('updated', true)
                ->where('task.status', 'done')
                ->where('task.due_date', null)
                ->where('task.assignee', 'Sam')
                ->etc());

        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('Logo', $task->fresh()->title);
    }

    public function test_a_task_on_a_project_you_are_not_on_is_refused(): void
    {
        $task = $this->theirs->tasks()->create(['title' => 'Secret']);

        $this->as($this->sam)->tool(UpdateTaskTool::class, ['task_id' => $task->id, 'status' => 'done'])
            ->assertHasErrors(['This action is unauthorized.']);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_it_needs_something_to_change_and_a_real_status(): void
    {
        $task = $this->mine->tasks()->create(['title' => 'Logo']);

        $this->as($this->sam)->tool(UpdateTaskTool::class, ['task_id' => $task->id])->assertHasErrors(['at least one thing to change']);
        $this->as($this->sam)->tool(UpdateTaskTool::class, ['task_id' => $task->id, 'status' => 'shipped'])->assertHasErrors(['status']);
    }
}
