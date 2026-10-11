<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetProjectTool;
use App\Models\Proposal;
use App\Models\TimeEntry;
use Illuminate\Testing\Fluent\AssertableJson;

class GetProjectToolTest extends McpTestCase
{
    public function test_it_gets_a_project_with_its_open_tasks_and_unbilled_hours(): void
    {
        $this->mine->tasks()->create(['title' => 'Logo']);
        $this->mine->tasks()->create(['title' => 'Old', 'status' => 'done']);
        TimeEntry::create(['project_id' => $this->mine->id, 'company_id' => $this->acme->id, 'user_id' => $this->sam->id, 'date' => '2026-10-01', 'hours' => 2.5]);

        $this->as($this->sam)->tool(GetProjectTool::class, ['project_id' => $this->mine->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('name', 'Rebrand')
                ->where('open_tasks', fn ($tasks) => count($tasks) === 1 && $tasks[0]['title'] === 'Logo')
                ->where('unbilled_hours', 2.5)
                ->where('team.0.name', 'Sam Staff')
                ->where('proposals', null) // no Proposals permission
                ->etc());
    }

    public function test_proposals_show_with_the_proposals_permission(): void
    {
        Proposal::create(['company_id' => $this->acme->id, 'project_id' => $this->mine->id, 'title' => 'Phase 2', 'body' => '<p>x</p>', 'status' => 'draft']);

        $this->as($this->admin)->tool(GetProjectTool::class, ['project_id' => $this->mine->id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('proposals.0.title', 'Phase 2')->etc());
    }

    public function test_a_project_you_are_not_on_is_refused(): void
    {
        $this->as($this->sam)->tool(GetProjectTool::class, ['project_id' => $this->theirs->id])
            ->assertHasErrors(['This action is unauthorized.'])
            ->assertDontSee('Secret');
    }

    public function test_an_unknown_project_is_a_validation_error(): void
    {
        $this->as($this->sam)->tool(GetProjectTool::class, ['project_id' => 999])->assertHasErrors(['project id']);
    }
}
