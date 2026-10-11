<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\CreateTaskTool;
use App\Models\Task;
use Illuminate\Testing\Fluent\AssertableJson;

class CreateTaskToolTest extends McpTestCase
{
    public function test_it_adds_a_task_to_your_project(): void
    {
        $this->as($this->sam)->tool(CreateTaskTool::class, [
            'project_id' => $this->mine->id,
            'title' => 'Draft logo options',
            'assignee' => 'Sam',
            'due_date' => '2026-10-20',
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('created', true)
                ->where('task.title', 'Draft logo options')
                ->where('task.status', 'todo')
                ->where('task.due_date', '2026-10-20')
                ->where('task.project.name', 'Rebrand')
                ->where('task.url', url("/projects/{$this->mine->id}?tab=Tasks"))
                ->etc());

        $this->assertDatabaseHas('tasks', ['project_id' => $this->mine->id, 'title' => 'Draft logo options', 'assignee' => 'Sam']);
    }

    public function test_a_project_you_are_not_on_is_refused(): void
    {
        $this->as($this->sam)->tool(CreateTaskTool::class, ['project_id' => $this->theirs->id, 'title' => 'Sneaky'])
            ->assertHasErrors(['This action is unauthorized.']);

        $this->assertSame(0, Task::count());
    }

    public function test_a_title_is_required(): void
    {
        $this->as($this->sam)->tool(CreateTaskTool::class, ['project_id' => $this->mine->id])->assertHasErrors(['title']);
    }
}
