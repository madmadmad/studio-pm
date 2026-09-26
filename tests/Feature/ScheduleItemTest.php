<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleItemTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);
    }

    public function test_a_schedule_item_can_be_added_edited_and_removed(): void
    {
        $user = User::factory()->create();
        $project = $this->project();

        $item = $this->actingAs($user)->postJson("/api/projects/{$project->id}/schedule-items", [
            'title' => 'Discovery',
            'description' => 'Interviews and audit',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-14',
        ])->assertCreated()->json();

        $this->assertSame('2026-10-01', $item['starts_on']);
        $this->assertSame('2026-10-14', $item['ends_on']);

        $this->actingAs($user)->patchJson("/api/schedule-items/{$item['id']}", ['ends_on' => '2026-10-21'])
            ->assertOk()
            ->assertJsonPath('ends_on', '2026-10-21');

        $this->actingAs($user)->deleteJson("/api/schedule-items/{$item['id']}")->assertNoContent();
        $this->assertSame(0, $project->scheduleItems()->count());
    }

    public function test_a_one_day_item_is_allowed(): void
    {
        $user = User::factory()->create();
        $project = $this->project();

        $this->actingAs($user)->postJson("/api/projects/{$project->id}/schedule-items", [
            'title' => 'Launch', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02',
        ])->assertCreated();
    }

    public function test_the_end_date_cannot_be_before_the_start_date(): void
    {
        $user = User::factory()->create();
        $project = $this->project();

        $this->actingAs($user)->postJson("/api/projects/{$project->id}/schedule-items", [
            'title' => 'Backwards', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');

        $item = $project->scheduleItems()->create(['title' => 'Design', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-20']);

        // Moving only the end date is checked against the saved start date.
        $this->actingAs($user)->patchJson("/api/schedule-items/{$item->id}", ['ends_on' => '2026-10-05'])
            ->assertUnprocessable();
    }

    public function test_new_items_go_to_the_end_of_the_list_whatever_their_dates(): void
    {
        $user = User::factory()->create();
        $project = $this->project();

        foreach ([['Build', '2026-11-01', '2026-11-30'], ['Discovery', '2026-10-01', '2026-10-14']] as [$title, $start, $end]) {
            $this->actingAs($user)->postJson("/api/projects/{$project->id}/schedule-items", [
                'title' => $title, 'starts_on' => $start, 'ends_on' => $end,
            ])->assertCreated();
        }

        $titles = collect($this->actingAs($user)->getJson("/api/projects/{$project->id}/schedule-items")->json())->pluck('title');

        $this->assertEquals(['Build', 'Discovery'], $titles->all());
    }

    public function test_items_can_be_reordered(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $a = $project->scheduleItems()->create(['title' => 'A', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'position' => 0]);
        $b = $project->scheduleItems()->create(['title' => 'B', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'position' => 1]);
        $c = $project->scheduleItems()->create(['title' => 'C', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'position' => 2]);

        $response = $this->actingAs($user)->putJson("/api/projects/{$project->id}/schedule-items/order", ['ids' => [$c->id, $a->id, $b->id]]);

        $response->assertOk();
        $this->assertEquals(['C', 'A', 'B'], collect($response->json())->pluck('title')->all());
        $this->assertEquals(['C', 'A', 'B'], $project->scheduleItems()->pluck('title')->all());
    }

    public function test_a_reorder_must_list_exactly_this_projects_items(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $a = $project->scheduleItems()->create(['title' => 'A', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
        $b = $project->scheduleItems()->create(['title' => 'B', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
        $elsewhere = $this->project()->scheduleItems()->create(['title' => 'Other', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);

        $this->actingAs($user)->putJson("/api/projects/{$project->id}/schedule-items/order", ['ids' => [$a->id]])->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/projects/{$project->id}/schedule-items/order", ['ids' => [$a->id, $b->id, $elsewhere->id]])->assertUnprocessable();
    }

    public function test_a_team_member_not_on_the_project_cannot_change_its_schedule(): void
    {
        $outsider = User::factory()->teamMember()->create();
        $project = $this->project();

        $this->actingAs($outsider)->postJson("/api/projects/{$project->id}/schedule-items", [
            'title' => 'Nope', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02',
        ])->assertForbidden();
    }
}
