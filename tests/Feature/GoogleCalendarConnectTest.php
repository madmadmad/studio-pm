<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.google.client_id' => 'test-client.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'http://localhost:8000/google/calendar/callback',
        ]);
    }

    private function idToken(string $email): string
    {
        $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $part(['alg' => 'RS256']).'.'.$part(['email' => $email, 'sub' => '123']).'.signature';
    }

    private function fakeGoogle(array $tokenResponse = [], array $calendarStatus = [200]): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response($tokenResponse + [
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3599,
                'id_token' => $this->idToken('bill@madmadmad.com'),
            ]),
            'oauth2.googleapis.com/revoke' => Http::response([]),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'studio@group.calendar.google.com', 'summary' => 'Studio', 'backgroundColor' => '#d93a2b', 'accessRole' => 'writer'],
                ['id' => 'bill@madmadmad.com', 'summary' => 'bill@madmadmad.com', 'backgroundColor' => '#4285f4', 'accessRole' => 'owner', 'primary' => true],
                ['id' => 'en.usa#holiday@group.v.calendar.google.com', 'summary' => 'Holidays', 'accessRole' => 'reader'],
            ]], ...$calendarStatus),
        ]);
    }

    private function connected(User $user, array $overrides = []): GoogleCalendarAccount
    {
        return GoogleCalendarAccount::create($overrides + [
            'user_id' => $user->id,
            'google_email' => 'bill@madmadmad.com',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
            'calendars' => [['id' => 'bill@madmadmad.com', 'name' => 'bill@madmadmad.com', 'color' => '#4285f4', 'can_edit' => true]],
        ]);
    }

    public function test_connect_sends_them_to_google_asking_for_offline_calendar_access(): void
    {
        $me = User::factory()->teamMember()->create(['email' => 'bill@madmadmad.com']);

        $response = $this->actingAs($me)->get('/google/calendar/connect');

        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/calendar.events', $query['scope']);
        $this->assertSame('bill@madmadmad.com', $query['login_hint']);
        $this->assertSame(session('google_calendar_state'), $query['state']);
    }

    public function test_coming_back_keeps_their_tokens_encrypted_and_shows_their_own_calendar(): void
    {
        $this->fakeGoogle();
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->withSession(['google_calendar_state' => 'abc'])
            ->get('/google/calendar/callback?state=abc&code=the-code')
            ->assertRedirect('/profile?calendar=connected');

        $account = $me->fresh()->googleCalendarAccount;
        $this->assertSame('bill@madmadmad.com', $account->google_email);
        $this->assertSame('refresh-1', $account->refresh_token);
        $this->assertSame(['bill@madmadmad.com'], collect($account->calendars)->pluck('id')->all());
        // Stored encrypted, never as the token itself.
        $this->assertNotSame('refresh-1', DB::table('google_calendar_accounts')->value('refresh_token'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['code'] === 'the-code');
    }

    public function test_a_wrong_state_or_a_cancel_connects_nothing(): void
    {
        Http::fake();
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->withSession(['google_calendar_state' => 'abc'])
            ->get('/google/calendar/callback?state=evil&code=x')->assertRedirect('/profile?calendar=failed');
        $this->actingAs($me)->withSession(['google_calendar_state' => 'abc'])
            ->get('/google/calendar/callback?error=access_denied&state=abc')->assertRedirect('/profile?calendar=cancelled');

        $this->assertNull($me->fresh()->googleCalendarAccount);
        Http::assertNothingSent();
    }

    public function test_they_see_their_calendars_and_choose_which_to_show(): void
    {
        $this->fakeGoogle();
        $me = User::factory()->teamMember()->create();
        $this->connected($me);

        $this->actingAs($me)->getJson('/api/google-calendar')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('chosen', ['bill@madmadmad.com'])
            ->assertJsonPath('available.0.id', 'bill@madmadmad.com') // their own first
            ->assertJsonPath('available.1.name', 'Holidays') // then by name
            ->assertJsonPath('available.1.can_edit', false); // view only

        $this->actingAs($me)->putJson('/api/google-calendar/calendars', ['ids' => ['bill@madmadmad.com', 'studio@group.calendar.google.com']])
            ->assertOk();
        $this->assertSame(
            [['id' => 'bill@madmadmad.com', 'name' => 'bill@madmadmad.com', 'color' => '#4285f4', 'can_edit' => true], ['id' => 'studio@group.calendar.google.com', 'name' => 'Studio', 'color' => '#d93a2b', 'can_edit' => true]],
            $me->googleCalendarAccount->fresh()->calendars,
        );

        $this->actingAs($me)->putJson('/api/google-calendar/calendars', ['ids' => ['someone-elses@group.calendar.google.com']])->assertStatus(422);
    }

    public function test_they_choose_which_calendar_new_events_go_on(): void
    {
        $this->fakeGoogle();
        $me = User::factory()->teamMember()->create();
        $account = $this->connected($me);
        $account->update(['calendars' => [
            ['id' => 'bill@madmadmad.com', 'name' => 'Bill', 'color' => null, 'can_edit' => true],
            ['id' => 'studio@group.calendar.google.com', 'name' => 'Studio', 'color' => null, 'can_edit' => true],
            ['id' => 'en.usa#holiday@group.v.calendar.google.com', 'name' => 'Holidays', 'color' => null, 'can_edit' => false],
        ]]);

        // Until they choose: the first they can edit.
        $this->actingAs($me)->getJson('/api/google-calendar')->assertJsonPath('default', 'bill@madmadmad.com');

        $this->actingAs($me)->putJson('/api/google-calendar/default', ['id' => 'studio@group.calendar.google.com'])
            ->assertOk()->assertJsonPath('default', 'studio@group.calendar.google.com');
        $this->actingAs($me)->putJson('/api/google-calendar/default', ['id' => 'en.usa#holiday@group.v.calendar.google.com'])->assertForbidden();

        // Hiding the default falls back to the next one they can edit.
        $this->actingAs($me)->putJson('/api/google-calendar/calendars', ['ids' => ['bill@madmadmad.com']])
            ->assertOk()->assertJsonPath('default', 'bill@madmadmad.com');
    }

    public function test_an_expired_token_is_renewed_before_calling_google(): void
    {
        $this->fakeGoogle(['access_token' => 'access-2']);
        $me = User::factory()->teamMember()->create();
        $this->connected($me, ['token_expires_at' => now()->subMinute()]);

        $this->actingAs($me)->getJson('/api/google-calendar')->assertOk();

        $this->assertSame('access-2', $me->googleCalendarAccount->fresh()->access_token);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'calendarList') && $r->hasHeader('Authorization', 'Bearer access-2'));
    }

    public function test_access_removed_in_google_disconnects_them(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $me = User::factory()->teamMember()->create();
        $this->connected($me, ['token_expires_at' => now()->subMinute()]);

        $this->actingAs($me)->getJson('/api/google-calendar')->assertOk()->assertJsonPath('connected', false)->assertJsonPath('lost', true);
        $this->assertNull($me->fresh()->googleCalendarAccount);
    }

    public function test_disconnecting_revokes_access_in_google(): void
    {
        $this->fakeGoogle();
        $me = User::factory()->teamMember()->create();
        $this->connected($me);

        $this->actingAs($me)->deleteJson('/api/google-calendar')->assertOk();

        $this->assertNull($me->fresh()->googleCalendarAccount);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'refresh-1');
    }

    public function test_without_a_google_client_there_is_nothing_to_connect(): void
    {
        config(['services.google.client_id' => null]);
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->get('/google/calendar/connect')->assertNotFound();
        $this->actingAs($me)->getJson('/api/google-calendar')->assertOk()->assertJsonPath('configured', false);
    }
}
