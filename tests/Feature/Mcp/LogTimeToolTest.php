<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\LogTimeTool;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

class LogTimeToolTest extends McpTestCase
{
    public function test_it_logs_your_time_today_by_default(): void
    {
        $task = $this->mine->tasks()->create(['title' => 'Logo']);

        $this->as($this->sam)->tool(LogTimeTool::class, ['project_id' => $this->mine->id, 'task_id' => $task->id, 'hours' => 1.5, 'note' => 'Sketches'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('logged', true)
                ->where('time_entry.hours', 1.5)
                ->where('time_entry.date', today()->toDateString())
                ->where('time_entry.person.name', 'Sam Staff')
                ->where('time_entry.task.title', 'Logo')
                ->where('time_entry.url', url("/projects/{$this->mine->id}?tab=Time"))
                ->etc());

        $this->assertDatabaseHas('time_entries', ['project_id' => $this->mine->id, 'company_id' => $this->acme->id, 'user_id' => $this->sam->id, 'billable' => true]);
    }

    public function test_the_service_decides_billable_as_in_the_app(): void
    {
        $service = Service::create(['name' => 'Admin', 'default_rate' => 0, 'unit' => 'hourly']);
        $service->forceFill(['billable' => false])->save();

        $this->as($this->sam)->tool(LogTimeTool::class, ['project_id' => $this->mine->id, 'hours' => 1, 'service_id' => $service->id])->assertOk();

        $this->assertFalse(TimeEntry::sole()->billable);
    }

    public function test_a_project_you_are_not_on_is_refused(): void
    {
        $this->as($this->sam)->tool(LogTimeTool::class, ['project_id' => $this->theirs->id, 'hours' => 1])
            ->assertHasErrors(['This action is unauthorized.']);

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_only_a_super_admin_logs_for_someone_else(): void
    {
        $alex = User::factory()->teamMember()->create(['permissions' => ['manage_projects']]);
        $this->mine->users()->attach($alex->id, ['assigned_at' => now()]);

        // Manage projects lets you in the app, not through Claude.
        $this->as($alex)->tool(LogTimeTool::class, ['project_id' => $this->mine->id, 'hours' => 1, 'user_id' => $this->sam->id])
            ->assertHasErrors(['Only super admins can log time for someone else here.']);

        $this->as($this->admin)->tool(LogTimeTool::class, ['project_id' => $this->mine->id, 'hours' => 2, 'user_id' => $this->sam->id])
            ->assertOk();
        $this->assertSame($this->sam->id, TimeEntry::sole()->user_id);

        // ...and only for someone on the project's team.
        $outsider = User::factory()->teamMember()->create();
        $this->as($this->admin)->tool(LogTimeTool::class, ['project_id' => $this->mine->id, 'hours' => 2, 'user_id' => $outsider->id])
            ->assertHasErrors(["That person isn't on this project's team."]);
    }

    public function test_hours_must_be_at_least_a_quarter(): void
    {
        $this->as($this->sam)->tool(LogTimeTool::class, ['project_id' => $this->mine->id, 'hours' => 0.1])->assertHasErrors(['hours']);
    }
}
