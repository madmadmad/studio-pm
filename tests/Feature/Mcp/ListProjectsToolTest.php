<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ListProjectsTool;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

class ListProjectsToolTest extends McpTestCase
{
    public function test_it_lists_the_projects_you_work_on_with_links(): void
    {
        $this->mine->tasks()->create(['title' => 'Logo']);

        $this->as($this->sam)->tool(ListProjectsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('count', 1)
                ->where('projects.0.id', $this->mine->id)
                ->where('projects.0.company.name', 'Acme')
                ->where('projects.0.open_tasks', 1)
                ->where('projects.0.url', url("/projects/{$this->mine->id}"))
                ->etc());
    }

    public function test_all_projects_sees_every_project_and_filters_apply(): void
    {
        $this->theirs->update(['status' => 'archived']);

        $this->as($this->admin)->tool(ListProjectsTool::class)
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->etc()); // archived left out
        $this->as($this->admin)->tool(ListProjectsTool::class, ['status' => 'archived'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('projects.0.name', 'Secret')->etc());
        $this->as($this->admin)->tool(ListProjectsTool::class, ['search' => 'Rebr'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->where('projects.0.name', 'Rebrand')->etc());
    }

    public function test_someone_on_no_projects_sees_none(): void
    {
        $outsider = User::factory()->teamMember()->create(['permissions' => []]);

        $this->as($outsider)->tool(ListProjectsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 0)->etc());
    }

    public function test_bad_input_is_refused(): void
    {
        $this->as($this->sam)->tool(ListProjectsTool::class, ['status' => 'nonsense'])->assertHasErrors(['status']);
    }
}
