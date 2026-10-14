<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_is_my_work_and_the_overview_is_still_there(): void
    {
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->get('/')->assertRedirect('/my-work');
        $this->actingAs($me)->get('/my-work')->assertOk()->assertInertia(fn ($page) => $page->component('MyWork/Index'));
        $this->actingAs($me)->get('/overview')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
    }

    public function test_recently_worked_on_is_their_own_time_over_30_days_newest_first(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $brand = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $site = $company->projects()->create(['name' => 'Website', 'status' => 'active']);
        $old = $company->projects()->create(['name' => 'Old campaign', 'status' => 'active']);
        $left = $company->projects()->create(['name' => 'Left it', 'status' => 'active']);
        $me = User::factory()->teamMember()->create();
        $colleague = User::factory()->teamMember()->create();
        foreach ([$brand, $site, $old] as $project) {
            $project->users()->attach([$me->id, $colleague->id], ['assigned_at' => now()]);
        }

        $log = fn (User $user, $project, string $date, float $hours) => TimeEntry::create([
            'company_id' => $company->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'date' => $date, 'hours' => $hours, 'billable' => true,
        ]);
        $log($me, $brand, '2026-10-02', 3);
        $log($me, $brand, '2026-10-06', 1.5);
        $log($me, $site, '2026-10-13', 2);
        $log($me, $old, '2026-09-10', 5); // more than 30 days ago
        $log($colleague, $site, '2026-10-14', 8); // someone else's
        $log($me, $left, '2026-10-12', 1); // a project they're not on (any more)

        $this->actingAs($me)->get('/my-work')->assertInertia(fn ($page) => $page
            ->has('recentProjects', 2)
            ->where('recentProjects.0.name', 'Website')
            ->where('recentProjects.0.last_worked_on', '2026-10-13')
            ->where('recentProjects.0.hours', 2)
            ->where('recentProjects.0.company.name', 'Alder & Finch Design')
            ->where('recentProjects.1.name', 'Brand refresh')
            ->where('recentProjects.1.last_worked_on', '2026-10-06')
            ->where('recentProjects.1.hours', 4.5));
    }

    public function test_profile_is_just_account_and_security_now(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')->assertInertia(fn ($page) => $page
            ->component('Profile/Index')
            ->has('security')
            ->missing('timesheet')
            ->missing('myTasks'));
    }
}
