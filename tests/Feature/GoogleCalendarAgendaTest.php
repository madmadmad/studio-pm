<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function connected(User $user, array $calendars): GoogleCalendarAccount
    {
        return GoogleCalendarAccount::create([
            'user_id' => $user->id,
            'google_email' => 'bill@madmadmad.com',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
            'calendars' => $calendars,
        ]);
    }

    public function test_the_agenda_lists_each_chosen_calendars_events_for_the_days_shown(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events*' => Http::response(['items' => [
                [
                    'id' => 'standup', 'summary' => 'Standup', 'status' => 'confirmed', 'htmlLink' => 'https://calendar.google.com/e/standup',
                    'start' => ['dateTime' => '2026-11-17T09:00:00-05:00'], 'end' => ['dateTime' => '2026-11-17T09:15:00-05:00'],
                    'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
                    'description' => '<b>Agenda</b><script>alert(1)</script> and notes',
                ],
                ['id' => 'gone', 'summary' => 'Cancelled', 'status' => 'cancelled', 'start' => ['dateTime' => '2026-11-17T10:00:00-05:00'], 'end' => ['dateTime' => '2026-11-17T11:00:00-05:00']],
                ['id' => 'nope', 'summary' => 'Declined', 'attendees' => [['email' => 'bill@madmadmad.com', 'self' => true, 'responseStatus' => 'declined']], 'start' => ['dateTime' => '2026-11-17T12:00:00-05:00'], 'end' => ['dateTime' => '2026-11-17T13:00:00-05:00']],
                ['id' => 'where', 'summary' => 'Office', 'eventType' => 'workingLocation', 'start' => ['date' => '2026-11-17'], 'end' => ['date' => '2026-11-18']],
            ]]),
            'www.googleapis.com/calendar/v3/calendars/studio%40group.calendar.google.com/events*' => Http::response(['items' => [
                ['id' => 'offsite', 'summary' => 'Studio offsite', 'location' => 'Toledo', 'start' => ['date' => '2026-11-18'], 'end' => ['date' => '2026-11-20']],
            ]]),
        ]);
        $me = User::factory()->teamMember()->create();
        $this->connected($me, [
            ['id' => 'bill@madmadmad.com', 'name' => 'Bill', 'color' => '#4285f4', 'can_edit' => true],
            ['id' => 'studio@group.calendar.google.com', 'name' => 'Studio', 'color' => '#d93a2b', 'can_edit' => true],
        ]);

        $response = $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-17&days=3')->assertOk();

        $response->assertJsonPath('connected', true)->assertJsonCount(2, 'events');
        $standup = $response->json('events.0');
        $this->assertSame(['Standup', false, '2026-11-17T09:00:00-05:00', 'https://meet.google.com/abc-defg-hij', 'Bill', '#4285f4'],
            [$standup['title'], $standup['all_day'], $standup['start'], $standup['join_url'], $standup['calendar'], $standup['color']]);
        $this->assertStringNotContainsString('<script', $standup['description']);
        $this->assertStringContainsString('<b>Agenda</b>', $standup['description']);
        $this->assertSame(['Studio offsite', true, '2026-11-18', '2026-11-20', 'Toledo'],
            collect($response->json('events.1'))->only(['title', 'all_day', 'start', 'end', 'location'])->values()->all());

        // Asked for the three days in New York time, repeats expanded.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'bill%40madmadmad.com/events')
            && $r['timeMin'] === '2026-11-17T00:00:00-05:00'
            && $r['timeMax'] === '2026-11-20T00:00:00-05:00'
            && $r['singleEvents'] === 'true');
    }

    public function test_paging_back_within_two_minutes_doesnt_ask_google_again(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);
        $me = User::factory()->teamMember()->create();
        $this->connected($me, [['id' => 'bill@madmadmad.com', 'name' => 'Bill', 'color' => null, 'can_edit' => true]]);

        $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-17&days=3')->assertOk();
        $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-17&days=3')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_without_a_connection_the_agenda_has_only_tasks(): void
    {
        Http::fake();
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-17&days=3')
            ->assertOk()->assertJsonPath('connected', false)->assertJsonPath('events', []);
        Http::assertNothingSent();
    }

    public function test_google_failing_says_so_instead_of_breaking_the_page(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Backend Error']], 503)]);
        $me = User::factory()->teamMember()->create();
        $this->connected($me, [['id' => 'bill@madmadmad.com', 'name' => 'Bill', 'color' => null, 'can_edit' => true]]);

        $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-17&days=3')
            ->assertOk()->assertJsonPath('events', [])->assertJsonPath('error', 'Your Google calendar couldn’t be loaded just now.');
    }

    public function test_the_range_is_one_to_seven_days(): void
    {
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->getJson('/api/google-calendar/events?start=2026-11-17&days=30')->assertStatus(422);
        $this->actingAs($me)->getJson('/api/google-calendar/events?start=next-week&days=3')->assertStatus(422);
    }
}
