<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ListTasksTool;
use Illuminate\Testing\Fluent\AssertableJson;

class ListTasksToolTest extends McpTestCase
{
    public function test_it_lists_open_tasks_on_your_projects_soonest_first(): void
    {
        $this->mine->tasks()->create(['title' => 'Later', 'due_date' => '2026-12-01', 'assignee' => 'Sam']);
        $this->mine->tasks()->create(['title' => 'Sooner', 'due_date' => '2026-11-01', 'status' => 'in_progress']);
        $this->mine->tasks()->create(['title' => 'Finished', 'status' => 'done']);
        $this->theirs->tasks()->create(['title' => 'Not yours']);

        $this->as($this->sam)->tool(ListTasksTool::class)
            ->assertOk()
            ->assertDontSee('Not yours')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('count', 2)
                ->where('tasks.0.title', 'Sooner')
                ->where('tasks.0.url', url("/projects/{$this->mine->id}?tab=Tasks"))
                ->where('tasks.1.title', 'Later')
                ->etc());

        $this->as($this->sam)->tool(ListTasksTool::class, ['status' => 'done'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->where('tasks.0.title', 'Finished')->etc());
        $this->as($this->sam)->tool(ListTasksTool::class, ['assignee' => 'sam', 'status' => 'all'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->where('tasks.0.title', 'Later')->etc());
    }

    public function test_asking_for_a_project_you_are_not_on_is_refused(): void
    {
        $this->theirs->tasks()->create(['title' => 'Not yours']);

        $this->as($this->sam)->tool(ListTasksTool::class, ['project_id' => $this->theirs->id])
            ->assertHasErrors(['This action is unauthorized.'])
            ->assertDontSee('Not yours');
    }
}
