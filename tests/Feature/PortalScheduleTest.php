<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Clients always see their project's schedule (read-only), in the
// studio's order, with only the fields the chart and list need.
class PortalScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_sees_the_schedule_in_the_studios_order(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();
        $project->scheduleItems()->create(['title' => 'Launch', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-23', 'position' => 1]);
        $project->scheduleItems()->create(['title' => 'Discovery', 'description' => 'Interviews', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-14', 'position' => 0]);

        $items = $this->actingAs($client, 'client')->get("/portal/projects/{$project->id}")->viewData('page')['props']['project']['schedule_items'];

        $this->assertEquals(['Discovery', 'Launch'], collect($items)->pluck('title')->all());
        $this->assertSame('2026-10-01', $items[0]['starts_on']);
        $this->assertSame('Interviews', $items[0]['description']);
        $this->assertArrayNotHasKey('created_at', $items[0]);
    }

    public function test_a_client_has_no_way_to_change_the_schedule(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();
        $item = $project->scheduleItems()->create(['title' => 'Discovery', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-14']);

        // The staff API is behind the staff guard; a client session doesn't reach it.
        $this->actingAs($client, 'client')->patchJson("/api/schedule-items/{$item->id}", ['title' => 'Changed'])->assertUnauthorized();
        $this->assertSame('Discovery', $item->fresh()->title);
    }
}
