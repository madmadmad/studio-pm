<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GoogleCalendarAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Linking a Google event to a project, and the project's upcoming
// meetings on its Schedule tab.
class GoogleCalendarProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function me(): User
    {
        $me = User::factory()->teamMember()->create();
        GoogleCalendarAccount::create([
            'user_id' => $me->id,
            'google_email' => 'bill@madmadmad.com',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
            'calendars' => [['id' => 'bill@madmadmad.com', 'name' => 'Bill', 'color' => '#4285f4', 'can_edit' => true]],
        ]);

        return $me;
    }

    private function project(User $on, string $name = 'Website'): Project
    {
        $project = Project::create(['company_id' => Company::create(['name' => 'Acme'])->id, 'name' => $name, 'status' => 'active']);
        $project->users()->attach($on->id);

        return $project;
    }

    private function googleEvent(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'evt1', 'etag' => '"v1"', 'summary' => 'Kickoff',
            'start' => ['dateTime' => '2026-11-18T10:00:00-05:00'], 'end' => ['dateTime' => '2026-11-18T11:00:00-05:00'],
        ];
    }

    public function test_a_new_event_carries_its_project_on_the_google_event(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->googleEvent())]);
        $me = $this->me();
        $project = $this->project($me);
        $event = ['calendar_id' => 'bill@madmadmad.com', 'title' => 'Kickoff', 'all_day' => false,
            'start_date' => '2026-11-18', 'start_time' => '10:00', 'end_date' => '2026-11-18', 'end_time' => '11:00'];

        $this->actingAs($me)->postJson('/api/google-calendar/events', $event + ['project_id' => $project->id])->assertOk();
        $this->actingAs($me)->postJson('/api/google-calendar/events', ['title' => 'Unlinked', 'project_id' => null] + $event)->assertOk();

        Http::assertSent(fn (Request $r) => $r['summary'] === 'Kickoff' && $r['extendedProperties']['shared']['studioProjectId'] === (string) $project->id);
        Http::assertSent(fn (Request $r) => $r['summary'] === 'Unlinked' && ! isset($r['extendedProperties']));
    }

    public function test_the_link_comes_off_and_only_goes_to_projects_you_work_on(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->googleEvent())]);
        $me = $this->me();
        $theirs = $this->project(User::factory()->teamMember()->create(), 'Not mine');

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', ['calendar_id' => 'bill@madmadmad.com', 'etag' => '"v1"', 'project_id' => null])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['extendedProperties' => ['shared' => ['studioProjectId' => null]]]);

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', ['calendar_id' => 'bill@madmadmad.com', 'project_id' => $theirs->id])->assertForbidden();
    }

    public function test_the_agenda_names_each_events_project(): void
    {
        $me = $this->me();
        $project = $this->project($me);
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
            $this->googleEvent(['extendedProperties' => ['shared' => ['studioProjectId' => (string) $project->id]]]),
            $this->googleEvent(['id' => 'evt2', 'summary' => 'Lunch']),
        ]])]);

        $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-18&days=1')
            ->assertJsonPath('events.0.project', ['id' => $project->id, 'name' => 'Website', 'client' => 'Acme'])
            ->assertJsonPath('events.1.project', null)
            ->assertJsonPath('projects.0.name', 'Website');
    }

    public function test_a_project_lists_its_upcoming_meetings_from_your_calendars(): void
    {
        $this->travelTo('2026-11-17 12:00:00');
        $me = $this->me();
        $project = $this->project($me);
        $linked = ['extendedProperties' => ['shared' => ['studioProjectId' => (string) $project->id]]];
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
            $this->googleEvent(['id' => 'earlier', 'summary' => 'Done already', 'start' => ['dateTime' => '2026-11-17T09:00:00-05:00'], 'end' => ['dateTime' => '2026-11-17T10:00:00-05:00']] + $linked),
            $this->googleEvent(['id' => 'review', 'summary' => 'Design review', 'start' => ['dateTime' => '2026-12-01T14:00:00-05:00'], 'end' => ['dateTime' => '2026-12-01T15:00:00-05:00']] + $linked),
            $this->googleEvent($linked),
        ]])]);

        $this->actingAs($me)->getJson("/api/projects/{$project->id}/meetings")
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('events.*.title', ['Kickoff', 'Design review']);

        Http::assertSent(fn (Request $r) => $r['sharedExtendedProperty'] === "studioProjectId={$project->id}" && $r['singleEvents'] === 'true');
    }

    public function test_a_projects_meetings_need_access_to_the_project_and_a_connection(): void
    {
        Http::fake();
        $theirs = $this->project(User::factory()->teamMember()->create());
        $this->actingAs($this->me())->getJson("/api/projects/{$theirs->id}/meetings")->assertForbidden();

        $unconnected = User::factory()->teamMember()->create();
        $mine = $this->project($unconnected, 'Mine');
        $this->actingAs($unconnected)->getJson("/api/projects/{$mine->id}/meetings")->assertOk()->assertJsonPath('connected', false);
        Http::assertNothingSent();
    }
}
