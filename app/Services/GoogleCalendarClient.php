<?php

namespace App\Services;

use App\Models\GoogleCalendarAccount;
use App\Support\RichText;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

// The Google calls the calendar needs, made as each team member: signing
// in (OAuth), keeping their access fresh, and their calendars. The tokens
// live encrypted on GoogleCalendarAccount and never reach the browser.
class GoogleCalendarClient
{
    // See and change events, and list the calendars to choose from; the
    // email says whose account it is.
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    // Google's sign-in page. `offline` and `consent` so Google always
    // hands back a refresh token, even on a reconnect.
    public function authUrl(string $state, ?string $loginHint = null): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(array_filter([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'login_hint' => $loginHint,
        ]));
    }

    /**
     * The code Google sent back, traded for tokens.
     *
     * @return array{email: string, access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.google.redirect'),
        ]);

        if (empty($tokens['refresh_token'])) {
            throw new RuntimeException('Google didn’t grant offline access.');
        }

        return [
            'email' => $this->emailFromIdToken($tokens['id_token'] ?? ''),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => (int) ($tokens['expires_in'] ?? 3600),
        ];
    }

    // A live access token, renewed a minute before it runs out. A refused
    // renewal (revoked in Google, the account removed) disconnects them.
    public function accessToken(GoogleCalendarAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isAfter(now()->addMinute())) {
            return $account->access_token;
        }

        try {
            $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
        } catch (RequestException $e) {
            if ($e->response->json('error') === 'invalid_grant') {
                $account->delete();
                throw new GoogleCalendarDisconnected('Google Calendar access was removed. Connect it again from your profile.');
            }
            throw $e;
        }

        $account->update([
            'access_token' => $tokens['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ]);

        return $account->access_token;
    }

    // Calls to the Calendar API as this person.
    public function api(GoogleCalendarAccount $account): PendingRequest
    {
        return Http::baseUrl('https://www.googleapis.com/calendar/v3')
            ->withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Every calendar on their Google list, theirs first.
     *
     * @return list<array{id: string, name: string, color: ?string, primary: bool, can_edit: bool}>
     */
    public function calendars(GoogleCalendarAccount $account): array
    {
        $items = [];
        $page = null;
        do {
            $response = $this->api($account)->get('users/me/calendarList', array_filter([
                'maxResults' => 250,
                'pageToken' => $page,
            ]))->throw();
            $items = [...$items, ...$response->json('items', [])];
            $page = $response->json('nextPageToken');
        } while ($page);

        return collect($items)
            ->map(fn (array $c) => [
                'id' => $c['id'],
                'name' => $c['summaryOverride'] ?? $c['summary'] ?? $c['id'],
                'color' => $c['backgroundColor'] ?? null,
                'primary' => (bool) ($c['primary'] ?? false),
                'can_edit' => in_array($c['accessRole'] ?? '', ['writer', 'owner'], true),
            ])
            ->sortBy([['primary', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * One calendar's events between two moments, each repeat of a
     * recurring event its own (Google expands them), soonest first.
     * Cancelled ones, ones they've declined, and working-location markers
     * are left out.
     *
     * @return list<array<string, mixed>>
     */
    public function events(GoogleCalendarAccount $account, array $calendar, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $items = [];
        $page = null;
        do {
            $response = $this->api($account)->get('calendars/'.rawurlencode($calendar['id']).'/events', array_filter([
                'timeMin' => $from->format(DATE_RFC3339),
                'timeMax' => $to->format(DATE_RFC3339),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'pageToken' => $page,
            ]))->throw();
            $items = [...$items, ...$response->json('items', [])];
            $page = $response->json('nextPageToken');
        } while ($page);

        return collect($items)
            ->reject(fn (array $e) => ($e['status'] ?? '') === 'cancelled'
                || ($e['eventType'] ?? 'default') === 'workingLocation'
                || collect($e['attendees'] ?? [])->contains(fn ($a) => ($a['self'] ?? false) && ($a['responseStatus'] ?? '') === 'declined'))
            ->map(fn (array $e) => $this->event($e, $calendar))
            ->values()
            ->all();
    }

    // An event as the Agenda shows it. All-day events keep plain dates
    // (Google's end date is the day after the last); timed ones keep their
    // moments with offset, for the page to show in the app's time zone.
    public function event(array $e, array $calendar): array
    {
        $allDay = isset($e['start']['date']);
        $join = $e['hangoutLink'] ?? collect($e['conferenceData']['entryPoints'] ?? [])->firstWhere('entryPointType', 'video')['uri'] ?? null;

        return [
            'id' => $e['id'],
            'calendar_id' => $calendar['id'],
            'calendar' => $calendar['name'],
            'color' => $calendar['color'] ?? null,
            'title' => $e['summary'] ?? '(No title)',
            'all_day' => $allDay,
            'start' => $allDay ? $e['start']['date'] : $e['start']['dateTime'],
            'end' => $allDay ? $e['end']['date'] : $e['end']['dateTime'],
            'location' => $e['location'] ?? null,
            // Google's notes can be HTML: cut down to plain formatting.
            'description' => RichText::toSafeHtml($e['description'] ?? null) ?: null,
            'join_url' => $join,
            'google_url' => $e['htmlLink'] ?? null,
            'type' => $e['eventType'] ?? 'default', // default, outOfOffice, focusTime
            // For editing (GoogleCalendarController): the version to check a
            // save against, the series a repeat belongs to, the notes as
            // plain text, and the guests.
            'etag' => $e['etag'] ?? null,
            'recurring_event_id' => $e['recurringEventId'] ?? null,
            'can_edit' => (bool) ($calendar['can_edit'] ?? false),
            'notes' => self::plainNotes($e['description'] ?? null),
            'has_meet' => isset($e['hangoutLink']),
            'guests' => collect($e['attendees'] ?? [])
                ->reject(fn ($a) => ($a['self'] ?? false) || ($a['resource'] ?? false))
                ->map(fn ($a) => ['email' => $a['email'], 'name' => $a['displayName'] ?? null, 'response' => $a['responseStatus'] ?? 'needsAction'])
                ->values()->all(),
        ];
    }

    // Google's notes (HTML or plain) as plain text to edit: line breaks kept.
    public static function plainNotes(?string $description): string
    {
        if (blank($description)) {
            return '';
        }
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h[1-6])>#i'], "\n", $description);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }

    /** One event as Google has it now (the series itself, for a recurring one). */
    public function getEvent(GoogleCalendarAccount $account, string $calendarId, string $eventId): array
    {
        return $this->api($account)->get($this->eventPath($calendarId, $eventId))->throw()->json();
    }

    // A new event; with $meet, Google adds a Meet link. Guests get Google's
    // invitation.
    public function createEvent(GoogleCalendarAccount $account, string $calendarId, array $body, bool $meet = false): array
    {
        if ($meet) {
            $body['conferenceData'] = ['createRequest' => ['requestId' => (string) Str::uuid(), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        }

        return $this->api($account)
            ->post('calendars/'.rawurlencode($calendarId).'/events?'.http_build_query(['sendUpdates' => 'all', 'conferenceDataVersion' => 1]), $body)
            ->throw()->json();
    }

    // Only the fields given change. With $etag, refused (GoogleCalendarConflict)
    // if the event changed in Google since it was read.
    public function updateEvent(GoogleCalendarAccount $account, string $calendarId, string $eventId, array $body, ?string $etag = null, bool $addMeet = false): array
    {
        if ($addMeet) {
            $body['conferenceData'] = ['createRequest' => ['requestId' => (string) Str::uuid(), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        }

        $response = $this->api($account)
            ->withHeaders(array_filter(['If-Match' => $etag]))
            ->patch($this->eventPath($calendarId, $eventId).'?'.http_build_query(['sendUpdates' => 'all', 'conferenceDataVersion' => 1]), $body);
        $this->refuseConflict($response);

        return $response->throw()->json();
    }

    public function deleteEvent(GoogleCalendarAccount $account, string $calendarId, string $eventId, ?string $etag = null): void
    {
        $response = $this->api($account)
            ->withHeaders(array_filter(['If-Match' => $etag]))
            ->delete($this->eventPath($calendarId, $eventId).'?'.http_build_query(['sendUpdates' => 'all']));
        $this->refuseConflict($response);
        if ($response->status() !== 410) { // already gone: as good as deleted
            $response->throw();
        }
    }

    private function eventPath(string $calendarId, string $eventId): string
    {
        return 'calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId);
    }

    private function refuseConflict(Response $response): void
    {
        if ($response->status() === 412) {
            throw new GoogleCalendarConflict('This event changed in Google since you opened it.');
        }
    }

    // Ends the app's access in Google too, so it leaves their account's
    // "Third-party apps" list. Best effort: they're disconnected here either way.
    public function revoke(GoogleCalendarAccount $account): void
    {
        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $account->refresh_token]);
        } catch (\Throwable) {
            // Already gone, or Google unreachable: nothing more to do.
        }
    }

    private function token(array $params): array
    {
        return Http::asForm()->acceptJson()->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                ...$params,
            ])
            ->throw()
            ->json();
    }

    // The account's email, from the ID token Google's token endpoint just
    // returned over TLS (so no signature check is needed for this).
    private function emailFromIdToken(string $idToken): string
    {
        $payload = explode('.', $idToken)[1] ?? '';
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true) ?: [];

        if (empty($claims['email'])) {
            throw new RuntimeException('Google didn’t say which account this is.');
        }

        return $claims['email'];
    }
}
