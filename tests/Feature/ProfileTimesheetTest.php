<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTimesheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_timesheet_shows_your_own_week_day_by_day(): void
    {
        $this->travelTo('2026-10-01 10:00:00'); // a Thursday
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $me = User::factory()->teamMember()->create();
        $colleague = User::factory()->teamMember()->create();
        $project->users()->attach([$me->id, $colleague->id], ['assigned_at' => now()]);

        $log = fn (User $user, string $date, float $hours, bool $billable = true) => TimeEntry::create([
            'company_id' => $company->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'date' => $date, 'hours' => $hours, 'billable' => $billable,
        ]);
        $log($me, '2026-09-28', 2.5);
        $log($me, '2026-10-01', 1, false);
        $log($me, '2026-09-27', 4); // the Sunday before -- last week
        $log($colleague, '2026-09-29', 8); // someone else's

        $this->actingAs($me)->get('/profile')->assertOk()->assertInertia(fn ($page) => $page
            ->where('timesheet.week_start', '2026-09-28')
            ->where('timesheet.week_end', '2026-10-04')
            ->where('timesheet.is_this_week', true)
            ->has('timesheet.days', 7)
            ->where('timesheet.days.0.hours', 2.5)
            ->has('timesheet.days.0.entries', 1)
            ->where('timesheet.days.0.entries.0.can_edit', true)
            ->has('timesheet.days.1.entries', 0)
            ->where('timesheet.totals.hours', 3.5)
            ->where('timesheet.totals.billable', 2.5)
            ->has('timeProjects', 1));

        $this->actingAs($me)->get('/profile?week=2026-09-23')->assertInertia(fn ($page) => $page
            ->where('timesheet.week_start', '2026-09-21')
            ->where('timesheet.is_this_week', false)
            ->where('timesheet.totals.hours', 4));
    }

    public function test_a_bad_week_falls_back_to_this_week(): void
    {
        $this->travelTo('2026-10-01 10:00:00');

        $this->actingAs(User::factory()->teamMember()->create())->get('/profile?week=not-a-date')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('timesheet.week_start', '2026-09-28'));
    }

    public function test_the_projects_tab_shows_only_your_starred_projects(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $starred = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $company->projects()->create(['name' => 'Website', 'status' => 'active']);
        $archived = $company->projects()->create(['name' => 'Old work', 'status' => 'archived']);
        $me = User::factory()->create();
        $me->favoriteProjects()->attach([$starred->id, $archived->id]);

        $this->actingAs($me)->get('/profile')->assertInertia(fn ($page) => $page
            ->has('starredProjects', 1)
            ->where('starredProjects.0.name', 'Brand refresh')
            ->where('starredProjects.0.is_favorite', true));

        // Someone else's stars aren't yours.
        $this->actingAs(User::factory()->create())->get('/profile')->assertInertia(fn ($page) => $page->has('starredProjects', 0));
    }
}
