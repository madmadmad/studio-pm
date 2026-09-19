<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntry(): TimeEntry
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $entry = TimeEntry::create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'date' => '2026-09-10',
            'hours' => 2,
            'note' => 'Initial pass',
        ]);

        $this->actingAs($user);

        return $entry;
    }

    public function test_date_hours_and_note_can_be_updated(): void
    {
        $entry = $this->makeEntry();

        $response = $this->patchJson("/api/time-entries/{$entry->id}", [
            'date' => '2026-09-11',
            'hours' => 3.5,
            'note' => 'Revised',
        ]);

        $response->assertOk();
        $entry->refresh();
        $this->assertSame('2026-09-11', $entry->date->toDateString());
        $this->assertEquals(3.5, $entry->hours);
        $this->assertSame('Revised', $entry->note);
    }

    public function test_a_time_entry_can_be_assigned_to_a_task_in_the_same_project(): void
    {
        $entry = $this->makeEntry();
        $task = $entry->project->tasks()->create(['title' => 'Design homepage']);

        $this->patchJson("/api/time-entries/{$entry->id}", ['task_id' => $task->id])->assertOk();

        $this->assertSame($task->id, $entry->fresh()->task_id);
    }

    public function test_a_task_from_a_different_project_is_rejected(): void
    {
        $entry = $this->makeEntry();
        $otherCompany = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherProject = $otherCompany->projects()->create(['name' => 'Menu redesign']);
        $otherTask = $otherProject->tasks()->create(['title' => 'Other task']);

        $response = $this->patchJson("/api/time-entries/{$entry->id}", ['task_id' => $otherTask->id]);

        $response->assertUnprocessable();
    }

    public function test_billable_can_be_toggled(): void
    {
        $entry = $this->makeEntry();

        $this->patchJson("/api/time-entries/{$entry->id}", ['billable' => false])->assertOk();

        $this->assertFalse($entry->fresh()->billable);
    }

    public function test_a_time_entry_can_be_deleted(): void
    {
        $entry = $this->makeEntry();

        $this->deleteJson("/api/time-entries/{$entry->id}")->assertNoContent();

        $this->assertDatabaseMissing('time_entries', ['id' => $entry->id]);
    }
}
