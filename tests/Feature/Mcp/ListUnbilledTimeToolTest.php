<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ListUnbilledTimeTool;
use App\Models\TimeEntry;
use Illuminate\Testing\Fluent\AssertableJson;

class ListUnbilledTimeToolTest extends McpTestCase
{
    private function entry(array $attributes): TimeEntry
    {
        return TimeEntry::create(['company_id' => $this->acme->id, 'user_id' => $this->sam->id, 'date' => '2026-10-01', 'hours' => 1, ...$attributes]);
    }

    public function test_it_lists_billable_unbilled_time_with_totals(): void
    {
        $this->entry(['project_id' => $this->mine->id, 'hours' => 2, 'note' => 'Sketches']);
        $this->entry(['project_id' => $this->mine->id, 'hours' => 1.5, 'date' => '2026-10-05']);
        $this->entry(['project_id' => $this->mine->id, 'hours' => 4, 'billed' => true]);
        $this->entry(['project_id' => $this->mine->id, 'hours' => 3, 'billable' => false]);
        $this->entry(['project_id' => $this->theirs->id, 'hours' => 8]);

        $this->as($this->sam)->tool(ListUnbilledTimeTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('total_hours', 3.5)
                ->where('count', 2)
                ->where('by_project.0.name', 'Rebrand')
                ->where('by_project.0.url', url("/projects/{$this->mine->id}?tab=Time"))
                ->where('entries.0.date', '2026-10-05')
                ->where('entries.1.note', 'Sketches')
                ->etc());

        $this->as($this->admin)->tool(ListUnbilledTimeTool::class)
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total_hours', 11.5)->etc());
        $this->as($this->sam)->tool(ListUnbilledTimeTool::class, ['from' => '2026-10-02'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total_hours', 1.5)->etc());
    }

    public function test_asking_for_a_project_you_are_not_on_is_refused(): void
    {
        $this->entry(['project_id' => $this->theirs->id, 'hours' => 8, 'note' => 'Hidden work']);

        $this->as($this->sam)->tool(ListUnbilledTimeTool::class, ['project_id' => $this->theirs->id])
            ->assertHasErrors(['This action is unauthorized.'])
            ->assertDontSee('Hidden work');
    }
}
