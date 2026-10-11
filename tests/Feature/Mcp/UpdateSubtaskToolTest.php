<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\UpdateSubtaskTool;
use Illuminate\Testing\Fluent\AssertableJson;

class UpdateSubtaskToolTest extends McpTestCase
{
    public function test_it_ticks_off_a_subtask(): void
    {
        $task = $this->mine->tasks()->create(['title' => 'Launch']);
        $subtask = $task->subtasks()->create(['title' => 'Test forms', 'assignee' => 'Sam', 'position' => 0]);

        $this->as($this->sam)->tool(UpdateSubtaskTool::class, ['subtask_id' => $subtask->id, 'status' => 'done'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('subtask_id', $subtask->id)
                ->where('task.subtasks.0.status', 'done')
                ->where('task.subtasks.0.assignee', 'Sam')
                ->etc());

        $this->assertSame('done', $subtask->fresh()->status);
    }

    public function test_a_subtask_on_a_project_you_are_not_on_is_refused(): void
    {
        $subtask = $this->theirs->tasks()->create(['title' => 'Secret'])->subtasks()->create(['title' => 'Hidden', 'position' => 0]);

        $this->as($this->sam)->tool(UpdateSubtaskTool::class, ['subtask_id' => $subtask->id, 'status' => 'done'])
            ->assertHasErrors(['This action is unauthorized.']);

        $this->assertNotSame('done', $subtask->fresh()->status);
    }

    public function test_it_needs_something_to_change(): void
    {
        $subtask = $this->mine->tasks()->create(['title' => 'Launch'])->subtasks()->create(['title' => 'X', 'position' => 0]);

        $this->as($this->sam)->tool(UpdateSubtaskTool::class, ['subtask_id' => $subtask->id])->assertHasErrors(['at least one thing to change']);
    }
}
