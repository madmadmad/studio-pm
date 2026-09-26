<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Time entries name the service they were for; the service decides
// whether the time is billable.
class TimeEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);
    }

    private function service(bool $billable, string $name = 'Design'): Service
    {
        return Service::create(['name' => $name, 'default_rate' => 150, 'unit' => 'hourly', 'billable' => $billable]);
    }

    public function test_services_are_billable_unless_turned_off(): void
    {
        $manager = User::factory()->create();

        $created = $this->actingAs($manager)->postJson('/api/services', ['name' => 'Design', 'default_rate' => 150, 'unit' => 'hourly'])->json();
        $this->assertTrue($created['billable']);

        $this->actingAs($manager)->patchJson("/api/services/{$created['id']}", ['billable' => false])->assertOk();
        $this->assertFalse(Service::find($created['id'])->billable);
    }

    public function test_an_entry_for_a_non_billable_service_is_non_billable(): void
    {
        $manager = User::factory()->create();
        $admin = $this->service(false, 'Admin');

        $entry = $this->actingAs($manager)->postJson('/api/time-entries', [
            'project_id' => $this->project()->id, 'service_id' => $admin->id, 'date' => '2026-10-01', 'hours' => 1,
        ])->assertCreated()->json();

        $this->assertFalse($entry['billable']);
        $this->assertSame('Admin', $entry['service']['name']);
    }

    public function test_an_entry_for_a_billable_service_is_billable(): void
    {
        $manager = User::factory()->create();
        $design = $this->service(true);

        $entry = $this->actingAs($manager)->postJson('/api/time-entries', [
            'project_id' => $this->project()->id, 'service_id' => $design->id, 'date' => '2026-10-01', 'hours' => 1,
        ])->assertCreated()->json();

        $this->assertTrue($entry['billable']);
    }

    public function test_changing_an_entrys_service_updates_billable(): void
    {
        $manager = User::factory()->create();
        $project = $this->project();
        $entry = TimeEntry::create(['company_id' => $project->company_id, 'project_id' => $project->id, 'user_id' => $manager->id, 'service_id' => $this->service(true)->id, 'date' => '2026-10-01', 'hours' => 1, 'billable' => true]);

        $this->actingAs($manager)->patchJson("/api/time-entries/{$entry->id}", ['service_id' => $this->service(false, 'Admin')->id])->assertOk();

        $this->assertFalse($entry->fresh()->billable);
    }

    public function test_billable_can_still_be_overridden_on_an_entry(): void
    {
        $manager = User::factory()->create();
        $project = $this->project();
        $entry = TimeEntry::create(['company_id' => $project->company_id, 'project_id' => $project->id, 'user_id' => $manager->id, 'service_id' => $this->service(false, 'Admin')->id, 'date' => '2026-10-01', 'hours' => 1, 'billable' => false]);

        $this->actingAs($manager)->patchJson("/api/time-entries/{$entry->id}", ['billable' => true])->assertOk();

        $this->assertTrue($entry->fresh()->billable);
    }

    public function test_a_billed_entry_keeps_its_billable_setting_when_its_service_changes(): void
    {
        $manager = User::factory()->create();
        $project = $this->project();
        $entry = TimeEntry::create(['company_id' => $project->company_id, 'project_id' => $project->id, 'user_id' => $manager->id, 'date' => '2026-10-01', 'hours' => 1, 'billable' => true, 'billed' => true]);

        $this->actingAs($manager)->patchJson("/api/time-entries/{$entry->id}", ['service_id' => $this->service(false, 'Admin')->id])->assertOk();

        $this->assertTrue($entry->fresh()->billable);
    }

    public function test_a_team_member_gets_service_names_but_no_rates(): void
    {
        $member = User::factory()->teamMember()->create();
        $project = $this->project();
        $project->users()->attach($member->id, ['assigned_at' => now()]);
        $this->service(true);

        $props = $this->actingAs($member)->get("/projects/{$project->id}")->viewData('page')['props'];

        $this->assertSame([], $props['services']);
        $this->assertEquals([['id' => 1, 'name' => 'Design', 'billable' => true]], $props['timeServices']);
    }
}
