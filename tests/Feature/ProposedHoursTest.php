<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The project page's Hours remaining card starts from the hours sold in
// the project's accepted proposals.
class ProposedHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposed_hours_are_the_hourly_lines_of_accepted_proposals(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $hourly = Service::create(['name' => 'Design', 'default_rate' => 140, 'unit' => 'hourly']);
        $fixed = Service::create(['name' => 'Print run', 'default_rate' => 900, 'unit' => 'fixed']);

        $accepted = $company->proposals()->create(['project_id' => $project->id, 'title' => 'Phase 1', 'body' => '<p>x</p>', 'status' => 'accepted']);
        $accepted->items()->create(['service_id' => $hourly->id, 'description' => 'Design', 'quantity' => 38, 'rate' => 140]);
        $accepted->items()->create(['service_id' => $hourly->id, 'description' => 'Design', 'quantity' => 12.5, 'rate' => 140]);
        $accepted->items()->create(['service_id' => $fixed->id, 'description' => 'Print run', 'quantity' => 2, 'rate' => 900]);
        $accepted->items()->create(['description' => 'Custom thing', 'quantity' => 5, 'rate' => 100]);

        $sent = $company->proposals()->create(['project_id' => $project->id, 'title' => 'Phase 2', 'body' => '<p>x</p>', 'status' => 'sent']);
        $sent->items()->create(['service_id' => $hourly->id, 'description' => 'Design', 'quantity' => 100, 'rate' => 140]);

        $this->assertEquals(50.5, $project->proposedHours());

        $props = $this->actingAs(User::factory()->create())->get("/projects/{$project->id}")->viewData('page')['props'];
        $this->assertEquals(50.5, $props['proposedHours']);
    }

    public function test_a_project_without_an_accepted_proposal_has_no_proposed_hours(): void
    {
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);

        $this->assertEquals(0, $project->proposedHours());
    }
}
