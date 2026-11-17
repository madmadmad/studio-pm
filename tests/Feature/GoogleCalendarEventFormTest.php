<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarEventFormTest extends TestCase
{
    use RefreshDatabase;

    private const MINE = ['id' => 'bill@madmadmad.com', 'name' => 'Bill', 'color' => '#4285f4', 'can_edit' => true];

    private const HOLIDAYS = ['id' => 'en.usa#holiday@group.v.calendar.google.com', 'name' => 'Holidays', 'color' => null, 'can_edit' => false];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // a fake that misses fails the test, never reaches Google
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
            'calendars' => [self::MINE, self::HOLIDAYS],
        ]);

        return $me;
    }

    private function googleEvent(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'evt1', 'etag' => '"v1"', 'summary' => 'Kickoff', 'htmlLink' => 'https://calendar.google.com/e/evt1',
            'start' => ['dateTime' => '2026-11-18T10:00:00-05:00', 'timeZone' => 'America/New_York'],
            'end' => ['dateTime' => '2026-11-18T11:00:00-05:00', 'timeZone' => 'America/New_York'],
        ];
    }

    public function test_a_new_event_goes_to_google_with_its_guests_and_a_meet_link(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*' => Http::response($this->googleEvent(['hangoutLink' => 'https://meet.google.com/x']))]);
        $me = $this->me();

        $this->actingAs($me)->postJson('/api/google-calendar/events', [
            'calendar_id' => 'bill@madmadmad.com',
            'title' => 'Kickoff',
            'all_day' => false,
            'start_date' => '2026-11-18', 'start_time' => '10:00',
            'end_date' => '2026-11-18', 'end_time' => '11:00',
            'location' => 'Studio',
            'notes' => "Bring the deck\nand samples",
            'guests' => ['client@example.com'],
            'add_meet' => true,
        ])->assertOk()->assertJsonPath('event.title', 'Kickoff')->assertJsonPath('event.join_url', 'https://meet.google.com/x');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), 'calendars/bill%40madmadmad.com/events?sendUpdates=all&conferenceDataVersion=1')
            && $r['summary'] === 'Kickoff'
            && $r['location'] === 'Studio'
            && $r['start']['dateTime'] === '2026-11-18T10:00:00-05:00'
            && $r['start']['timeZone'] === 'America/New_York'
            && $r['attendees'] === [['email' => 'client@example.com']]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['description'] === "Bring the deck\nand samples"
            && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet');
    }

    public function test_a_new_event_can_repeat_worded_from_its_start_date(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->googleEvent())]);
        $me = $this->me();
        // Tuesday, November 17, 2026: the third Tuesday.
        $event = ['calendar_id' => 'bill@madmadmad.com', 'title' => 'Standup', 'all_day' => false,
            'start_date' => '2026-11-17', 'start_time' => '09:00', 'end_date' => '2026-11-17', 'end_time' => '09:15'];

        $cases = [
            [['repeat' => 'weekly', 'repeat_ends' => 'never'], 'RRULE:FREQ=WEEKLY'],
            [['repeat' => 'weekdays', 'repeat_ends' => 'after', 'repeat_count' => 10], 'RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR;COUNT=10'],
            [['repeat' => 'biweekly', 'repeat_ends' => 'on', 'repeat_until' => '2027-02-16'], 'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20270217T045959Z'],
            [['repeat' => 'monthly_weekday'], 'RRULE:FREQ=MONTHLY;BYDAY=3TU'],
        ];
        foreach ($cases as [$repeat, $rule]) {
            $this->actingAs($me)->postJson('/api/google-calendar/events', $event + $repeat)->assertOk();
            Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['recurrence'] === [$rule]);
        }

        // The fifth Tuesday of a month is "the last"; an all-day series ends on a date.
        $this->actingAs($me)->postJson('/api/google-calendar/events', [
            'calendar_id' => 'bill@madmadmad.com', 'title' => 'Wrap-up', 'all_day' => true,
            'start_date' => '2026-12-29', 'end_date' => '2026-12-29',
            'repeat' => 'monthly_weekday', 'repeat_ends' => 'on', 'repeat_until' => '2027-12-31',
        ])->assertOk();
        Http::assertSent(fn (Request $r) => $r['recurrence'] === ['RRULE:FREQ=MONTHLY;BYDAY=-1TU;UNTIL=20271231']);

        // Doesn't repeat: no rule.
        $this->actingAs($me)->postJson('/api/google-calendar/events', ['title' => 'Once', 'repeat' => 'none'] + $event)->assertOk();
        Http::assertSent(fn (Request $r) => $r['summary'] === 'Once' && $r['recurrence'] === []);
    }

    public function test_a_repeat_needs_a_sensible_end(): void
    {
        Http::fake();
        $me = $this->me();
        $event = ['calendar_id' => 'bill@madmadmad.com', 'title' => 'X', 'all_day' => false, 'start_date' => '2026-11-17',
            'start_time' => '09:00', 'end_date' => '2026-11-17', 'end_time' => '10:00', 'repeat' => 'weekly'];

        $this->actingAs($me)->postJson('/api/google-calendar/events', $event + ['repeat_ends' => 'on', 'repeat_until' => '2026-11-01'])->assertStatus(422);
        $this->actingAs($me)->postJson('/api/google-calendar/events', $event + ['repeat_ends' => 'after', 'repeat_count' => 1])->assertStatus(422);
        $this->actingAs($me)->postJson('/api/google-calendar/events', ['repeat' => 'hourly'] + $event)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_one_off_event_can_be_made_to_repeat(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->googleEvent())]);
        $me = $this->me();

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', [
            'calendar_id' => 'bill@madmadmad.com', 'etag' => '"v1"', 'repeat' => 'weekly', 'repeat_ends' => 'after', 'repeat_count' => 6,
            'all_day' => false, 'start_date' => '2026-11-18', 'start_time' => '10:00', 'end_date' => '2026-11-18', 'end_time' => '11:00',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['recurrence'] === ['RRULE:FREQ=WEEKLY;COUNT=6']);
    }

    public function test_an_all_day_event_ends_the_day_after_its_last_day_in_google(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->googleEvent(['start' => ['date' => '2026-11-18'], 'end' => ['date' => '2026-11-20']]))]);
        $me = $this->me();

        $this->actingAs($me)->postJson('/api/google-calendar/events', [
            'calendar_id' => 'bill@madmadmad.com', 'title' => 'Offsite', 'all_day' => true,
            'start_date' => '2026-11-18', 'end_date' => '2026-11-19',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r['start']['date'] === '2026-11-18' && $r['end']['date'] === '2026-11-20');
    }

    public function test_only_editable_chosen_calendars_take_events_and_the_end_follows_the_start(): void
    {
        Http::fake();
        $me = $this->me();
        $event = ['title' => 'X', 'all_day' => false, 'start_date' => '2026-11-18', 'start_time' => '10:00', 'end_date' => '2026-11-18', 'end_time' => '11:00'];

        $this->actingAs($me)->postJson('/api/google-calendar/events', ['calendar_id' => self::HOLIDAYS['id']] + $event)->assertForbidden();
        $this->actingAs($me)->postJson('/api/google-calendar/events', ['calendar_id' => 'someone@else.com'] + $event)->assertStatus(422);
        $this->actingAs($me)->postJson('/api/google-calendar/events', ['calendar_id' => 'bill@madmadmad.com', 'end_time' => '09:00'] + $event)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_an_edit_sends_only_what_changed_checked_against_the_version_opened(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->googleEvent(['summary' => 'Kickoff (moved)']))]);
        $me = $this->me();

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', [
            'calendar_id' => 'bill@madmadmad.com', 'etag' => '"v1"', 'title' => 'Kickoff (moved)',
        ])->assertOk()->assertJsonPath('event.title', 'Kickoff (moved)');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), 'events/evt1?')
            && $r->hasHeader('If-Match', '"v1"')
            && array_keys($r->data()) === ['summary']);
    }

    public function test_an_event_changed_in_google_meanwhile_isnt_overwritten(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 412]], 412)]);
        $me = $this->me();

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', ['calendar_id' => 'bill@madmadmad.com', 'etag' => '"v1"', 'title' => 'Mine'])
            ->assertStatus(409)->assertJsonPath('message', 'This event changed in Google since you opened it. Here’s the latest; make your change again.');
    }

    public function test_changing_all_events_moves_the_series_by_as_much_as_the_repeat_moved(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1*' => Http::sequence()
                ->push($this->googleEvent(['id' => 'series1', 'etag' => '"s1"', 'start' => ['dateTime' => '2026-09-01T09:00:00-04:00', 'timeZone' => 'America/New_York'], 'end' => ['dateTime' => '2026-09-01T09:30:00-04:00', 'timeZone' => 'America/New_York']]))
                ->push($this->googleEvent(['id' => 'series1'])),
        ]);
        $me = $this->me();

        // This week's standup, 9:00-9:30, moved to 9:30-10:00 for all of them.
        $this->actingAs($me)->patchJson('/api/google-calendar/events/series1_20261118T140000Z', [
            'calendar_id' => 'bill@madmadmad.com', 'scope' => 'all', 'recurring_event_id' => 'series1',
            'original_start' => '2026-11-18T09:00:00-05:00', 'original_end' => '2026-11-18T09:30:00-05:00',
            'all_day' => false, 'start_date' => '2026-11-18', 'start_time' => '09:30', 'end_date' => '2026-11-18', 'end_time' => '10:00',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), 'events/series1?')
            && $r->hasHeader('If-Match', '"s1"')
            && $r['start']['dateTime'] === '2026-09-01T09:30:00-04:00'
            && $r['end']['dateTime'] === '2026-09-01T10:00:00-04:00');
    }

    private function weeklySeries(array $recurrence): array
    {
        return $this->googleEvent([
            'id' => 'series1', 'etag' => '"s1"', 'summary' => 'Standup', 'location' => 'Studio', 'recurrence' => $recurrence,
            'attendees' => [['email' => 'client@example.com', 'responseStatus' => 'accepted']],
            'start' => ['dateTime' => '2026-11-03T09:00:00-05:00', 'timeZone' => 'America/New_York'],
            'end' => ['dateTime' => '2026-11-03T09:30:00-05:00', 'timeZone' => 'America/New_York'],
        ]);
    }

    // This week's (the third) standup of a weekly series.
    private const THIRD = [
        'calendar_id' => 'bill@madmadmad.com', 'scope' => 'following', 'recurring_event_id' => 'series1',
        'split_at' => '2026-11-17T09:00:00-05:00', 'original_start' => '2026-11-17T09:00:00-05:00', 'original_end' => '2026-11-17T09:30:00-05:00',
    ];

    public function test_changing_this_and_following_splits_the_series(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1/instances*' => Http::response(['items' => [['id' => 'a'], ['id' => 'b']]]),
            // The series as read, then as changed (exact paths: /instances is its own).
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1' => Http::response($this->weeklySeries(['RRULE:FREQ=WEEKLY;COUNT=10', 'EXDATE;TZID=America/New_York:20261110T090000'])),
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1?*' => Http::response($this->weeklySeries([])),
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events?*' => Http::response($this->googleEvent(['id' => 'series2'])),
        ]);
        $me = $this->me();

        // From this week on: 9:30 and a new name.
        $this->actingAs($me)->patchJson('/api/google-calendar/events/series1_20261117T140000Z', self::THIRD + [
            'title' => 'Standup (new time)',
            'all_day' => false, 'start_date' => '2026-11-17', 'start_time' => '09:30', 'end_date' => '2026-11-17', 'end_time' => '10:00',
        ])->assertOk();

        // The old series stops the second before this one, checked against its version.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), 'events/series1?')
            && $r->hasHeader('If-Match', '"s1"')
            && $r['recurrence'] === ['RRULE:FREQ=WEEKLY;UNTIL=20261117T135959Z', 'EXDATE;TZID=America/New_York:20261110T090000']);
        // The rest: a copy with the changes, the 8 repeats left of 10, the guest's reply kept.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['summary'] === 'Standup (new time)'
            && $r['location'] === 'Studio'
            && $r['start']['dateTime'] === '2026-11-17T09:30:00-05:00'
            && $r['recurrence'][0] === 'RRULE:FREQ=WEEKLY;COUNT=8'
            && $r['attendees'] === [['email' => 'client@example.com', 'responseStatus' => 'accepted']]);
    }

    public function test_this_and_following_from_the_first_repeat_changes_the_whole_series(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1/instances*' => Http::response(['items' => []]),
            // The series as read, then as changed (exact paths: /instances is its own).
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1' => Http::response($this->weeklySeries(['RRULE:FREQ=WEEKLY'])),
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1?*' => Http::response($this->weeklySeries(['RRULE:FREQ=WEEKLY'])),
        ]);
        $me = $this->me();

        $this->actingAs($me)->patchJson('/api/google-calendar/events/series1_20261103T140000Z', ['title' => 'Renamed'] + self::THIRD)->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['summary'] === 'Renamed' && ! isset($r['recurrence']));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_deleting_this_and_following_ends_the_series_before_it(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1/instances*' => Http::response(['items' => [['id' => 'a']]]),
            // The series as read, then as changed (exact paths: /instances is its own).
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1' => Http::response($this->weeklySeries(['RRULE:FREQ=WEEKLY;UNTIL=20271231T000000Z'])),
            'www.googleapis.com/calendar/v3/calendars/bill%40madmadmad.com/events/series1?*' => Http::response($this->weeklySeries([])),
        ]);
        $me = $this->me();

        $this->actingAs($me)->deleteJson('/api/google-calendar/events/series1_20261117T140000Z?'.http_build_query(self::THIRD))->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['recurrence'] === ['RRULE:FREQ=WEEKLY;UNTIL=20261117T135959Z']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_guests_keep_their_replies_when_the_list_changes(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::sequence()
                ->push($this->googleEvent(['attendees' => [
                    ['email' => 'bill@madmadmad.com', 'self' => true, 'responseStatus' => 'accepted'],
                    ['email' => 'Client@example.com', 'responseStatus' => 'accepted'],
                    ['email' => 'old@example.com', 'responseStatus' => 'declined'],
                ]]))
                ->push($this->googleEvent()),
        ]);
        $me = $this->me();

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', [
            'calendar_id' => 'bill@madmadmad.com', 'etag' => '"v1"', 'guests' => ['client@example.com', 'new@example.com'],
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['attendees'] === [
            ['email' => 'bill@madmadmad.com', 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'Client@example.com', 'responseStatus' => 'accepted'],
            ['email' => 'new@example.com'],
        ]);
    }

    public function test_deleting_one_repeat_or_the_whole_series(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(null, 204)]);
        $me = $this->me();

        $this->actingAs($me)->deleteJson('/api/google-calendar/events/series1_20261118T140000Z?calendar_id=bill%40madmadmad.com&etag=%22v1%22&scope=this&recurring_event_id=series1')->assertOk();
        $this->actingAs($me)->deleteJson('/api/google-calendar/events/series1_20261118T140000Z?calendar_id=bill%40madmadmad.com&scope=all&recurring_event_id=series1')->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'events/series1_20261118T140000Z?sendUpdates=all') && $r->hasHeader('If-Match', '"v1"'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'events/series1?sendUpdates=all'));
    }

    public function test_editing_needs_a_connected_calendar(): void
    {
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->patchJson('/api/google-calendar/events/evt1', ['calendar_id' => 'x', 'title' => 'Y'])
            ->assertStatus(422)->assertJsonPath('message', 'Connect your Google Calendar from your profile first.');
    }
}
