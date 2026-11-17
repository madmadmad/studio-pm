<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarAccount;
use App\Services\GoogleCalendarClient;
use App\Services\GoogleCalendarConflict;
use App\Services\GoogleCalendarDisconnected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Connecting a team member's Google Calendar (Profile → Google Calendar):
// Google's sign-in and back, choosing which calendars to show, and
// disconnecting. Each person connects their own madmadmad.com account.
class GoogleCalendarController extends Controller
{
    public function __construct(private GoogleCalendarClient $google) {}

    // Off to Google's sign-in, with a one-time state to check on the way back.
    public function connect(Request $request): RedirectResponse
    {
        abort_unless($this->google->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put('google_calendar_state', $state);

        return redirect()->away($this->google->authUrl($state, $request->user()->email));
    }

    // Back from Google: keep their tokens, then on to Profile to pick calendars.
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_calendar_state');

        if ($request->query('error')) {
            // They pressed Cancel on Google's screen.
            return redirect('/profile?calendar=cancelled');
        }
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->query('code')) {
            return redirect('/profile?calendar=failed');
        }

        try {
            $tokens = $this->google->exchangeCode((string) $request->query('code'));
        } catch (\Throwable $e) {
            Log::warning('Google Calendar connect failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return redirect('/profile?calendar=failed');
        }

        $account = GoogleCalendarAccount::updateOrCreate(['user_id' => $request->user()->id], [
            'google_email' => $tokens['email'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);

        // A first connection starts with their own calendar shown.
        if ($account->calendars === null) {
            try {
                $own = collect($this->google->calendars($account))->firstWhere('primary', true);
                $account->update(['calendars' => $own ? [$this->stored($own)] : []]);
            } catch (\Throwable) {
                $account->update(['calendars' => []]);
            }
        }

        return redirect('/profile?calendar=connected');
    }

    // Their connection, and every calendar they could show.
    public function show(Request $request): array
    {
        $account = $request->user()->googleCalendarAccount;
        if (! $account) {
            return ['configured' => $this->google->isConfigured(), 'connected' => false];
        }

        try {
            $available = $this->google->calendars($account);
        } catch (GoogleCalendarDisconnected) {
            return ['configured' => true, 'connected' => false, 'lost' => true];
        } catch (\Throwable $e) {
            Log::warning('Google calendar list failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);
            $available = null;
        }

        return [
            'configured' => true,
            'connected' => true,
            'email' => $account->google_email,
            'chosen' => collect($account->calendars ?? [])->pluck('id')->values(),
            'default' => $account->defaultCalendarId(),
            'available' => $available,
        ];
    }

    // Which calendars to show.
    public function updateCalendars(Request $request): array
    {
        $account = $request->user()->googleCalendarAccount;
        abort_unless($account, 404);

        $data = $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['string']]);
        $available = collect($this->google->calendars($account))->keyBy('id');
        $unknown = collect($data['ids'])->reject(fn ($id) => $available->has($id));
        if ($unknown->isNotEmpty()) {
            abort(422, 'That calendar isn’t on your Google list any more. Reload and try again.');
        }

        $account->update(['calendars' => collect($data['ids'])->map(fn ($id) => $this->stored($available[$id]))->values()->all()]);

        return ['chosen' => $data['ids'], 'default' => $account->defaultCalendarId()];
    }

    // Which calendar new events go on: one they show and can edit.
    public function updateDefault(Request $request): array
    {
        $account = $this->account($request);
        $data = $request->validate(['id' => ['required', 'string']]);
        $this->editableCalendar($account, $data['id']);
        $account->update(['default_calendar_id' => $data['id']]);

        return ['default' => $account->defaultCalendarId()];
    }

    // The Agenda's events (Home → Agenda): every chosen calendar's, from
    // ?start (a date) for ?days, in the app's time zone. Kept two minutes,
    // so paging back and forth doesn't ask Google each time.
    public function events(Request $request): array
    {
        $data = $request->validate(['start' => ['required', 'date_format:Y-m-d'], 'days' => ['required', 'integer', 'min:1', 'max:7']]);
        $account = $request->user()->googleCalendarAccount;
        if (! $account) {
            return ['connected' => false, 'events' => []];
        }

        $calendars = $account->calendars ?? [];
        $from = Carbon::parse($data['start'], config('app.timezone'))->startOfDay();
        $to = $from->copy()->addDays((int) $data['days']);
        $key = 'google-calendar-events:'.$account->id.':'.md5(json_encode([$data, collect($calendars)->pluck('id'), $account->updated_at]));

        try {
            $events = Cache::remember($key, now()->addMinutes(2), fn () => collect($calendars)
                ->flatMap(fn (array $calendar) => $this->google->events($account, $calendar, $from, $to))
                ->values()
                ->all());
        } catch (GoogleCalendarDisconnected) {
            return ['connected' => false, 'lost' => true, 'events' => []];
        } catch (\Throwable $e) {
            Log::warning('Google calendar events failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return ['connected' => true, 'events' => [], 'error' => 'Your Google calendar couldn’t be loaded just now.'];
        }

        // The chosen calendars too: the event form offers the ones they can edit.
        return ['connected' => true, 'events' => $events, 'calendars' => array_values($calendars), 'default_calendar_id' => $account->defaultCalendarId()];
    }

    // A new event on one of their chosen calendars they can edit.
    public function storeEvent(Request $request): array
    {
        $account = $this->account($request);
        $data = $request->validate([
            'calendar_id' => ['required', 'string'],
            ...$this->eventRules(required: true),
        ]);
        $calendar = $this->editableCalendar($account, $data['calendar_id']);

        $body = $this->withGuests($this->body($data), $data, []);
        $created = $this->google->createEvent($account, $calendar['id'], $body, (bool) ($data['add_meet'] ?? false));
        $account->touch(); // the Agenda's cache moves on

        return ['event' => $this->google->event($created, $calendar)];
    }

    // Changes to an event: only the fields sent. A repeat changes just
    // itself (scope=this) or the whole series (scope=all), which moves by
    // as much as this one did.
    public function updateEvent(Request $request, string $event): array
    {
        $account = $this->account($request);
        $data = $request->validate([
            'calendar_id' => ['required', 'string'],
            'etag' => ['nullable', 'string'],
            'scope' => ['nullable', 'in:this,all'],
            'recurring_event_id' => ['nullable', 'string'],
            'original_start' => ['nullable', 'string'],
            'original_end' => ['nullable', 'string'],
            ...$this->eventRules(required: false),
        ]);
        $calendar = $this->editableCalendar($account, $data['calendar_id']);
        $series = ($data['scope'] ?? 'this') === 'all' && filled($data['recurring_event_id'] ?? null);

        try {
            if ($series) {
                $master = $this->google->getEvent($account, $calendar['id'], $data['recurring_event_id']);
                $body = $this->body($data, $master, $data['original_start'] ?? null, $data['original_end'] ?? null);
                $body = $this->withGuests($body, $data, $master);
                $updated = $this->google->updateEvent($account, $calendar['id'], $master['id'], $body, $master['etag'] ?? null, (bool) ($data['add_meet'] ?? false));
            } else {
                $body = $this->body($data);
                if (array_key_exists('guests', $data)) {
                    $body = $this->withGuests($body, $data, $this->google->getEvent($account, $calendar['id'], $event));
                }
                $updated = $this->google->updateEvent($account, $calendar['id'], $event, $body, $data['etag'] ?? null, (bool) ($data['add_meet'] ?? false));
            }
        } catch (GoogleCalendarConflict $e) {
            $account->touch();
            abort(409, $e->getMessage().' Here’s the latest; make your change again.');
        }

        $account->touch();

        return ['event' => $this->google->event($updated, $calendar)];
    }

    public function destroyEvent(Request $request, string $event): array
    {
        $account = $this->account($request);
        $data = $request->validate([
            'calendar_id' => ['required', 'string'],
            'etag' => ['nullable', 'string'],
            'scope' => ['nullable', 'in:this,all'],
            'recurring_event_id' => ['nullable', 'string'],
        ]);
        $calendar = $this->editableCalendar($account, $data['calendar_id']);
        $series = ($data['scope'] ?? 'this') === 'all' && filled($data['recurring_event_id'] ?? null);

        try {
            $this->google->deleteEvent($account, $calendar['id'], $series ? $data['recurring_event_id'] : $event, $series ? null : ($data['etag'] ?? null));
        } catch (GoogleCalendarConflict $e) {
            $account->touch();
            abort(409, $e->getMessage().' Here’s the latest; delete it again if you still want to.');
        }
        $account->touch();

        return ['deleted' => true];
    }

    private function account(Request $request): GoogleCalendarAccount
    {
        $account = $request->user()->googleCalendarAccount;
        abort_unless($account, 422, 'Connect your Google Calendar from your profile first.');

        return $account;
    }

    // One of the calendars they show, and one they can change.
    private function editableCalendar(GoogleCalendarAccount $account, string $id): array
    {
        $calendar = collect($account->calendars ?? [])->firstWhere('id', $id);
        abort_unless($calendar, 422, 'That calendar isn’t one you show. Choose it on your profile first.');
        abort_unless($calendar['can_edit'] ?? false, 403, 'You can only view that calendar.');

        return $calendar;
    }

    // The form's fields. The date and time ones come together; all-day
    // events take dates only, and the end date is the last day.
    private function eventRules(bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';

        return [
            'title' => [$need, 'string', 'max:255'],
            'all_day' => [$need, 'boolean'],
            'start_date' => [$need, 'date_format:Y-m-d'],
            'end_date' => [$need, 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'required_if:all_day,false', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_if:all_day,false', 'date_format:H:i'],
            'location' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'guests' => ['sometimes', 'array', 'max:100'],
            'guests.*' => ['email'],
            'add_meet' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Google's event fields from the form's. With $master (a series being
     * changed through one of its repeats), the times move the series by as
     * much as the repeat moved from $originalStart/$originalEnd.
     */
    private function body(array $data, ?array $master = null, ?string $originalStart = null, ?string $originalEnd = null): array
    {
        $body = [];
        if (array_key_exists('title', $data)) {
            $body['summary'] = $data['title'];
        }
        if (array_key_exists('location', $data)) {
            $body['location'] = (string) $data['location'];
        }
        if (array_key_exists('notes', $data)) {
            $body['description'] = (string) $data['notes'];
        }

        if (array_key_exists('start_date', $data)) {
            $zone = config('app.timezone');
            if ($data['all_day']) {
                [$start, $end] = [
                    ['date' => $data['start_date'], 'dateTime' => null, 'timeZone' => null],
                    ['date' => Carbon::parse($data['end_date'])->addDay()->toDateString(), 'dateTime' => null, 'timeZone' => null],
                ];
            } else {
                $from = Carbon::parse("{$data['start_date']} {$data['start_time']}", $zone);
                $to = Carbon::parse("{$data['end_date']} {$data['end_time']}", $zone);
                abort_if($to->lte($from), 422, 'The end has to be after the start.');
                [$start, $end] = [
                    ['dateTime' => $from->toIso8601String(), 'timeZone' => $zone, 'date' => null],
                    ['dateTime' => $to->toIso8601String(), 'timeZone' => $zone, 'date' => null],
                ];
            }

            if ($master && $originalStart && $originalEnd) {
                [$start, $end] = [
                    $this->shift($master['start'], $originalStart, $start, $data['all_day']),
                    $this->shift($master['end'], $originalEnd, $end, $data['all_day']),
                ];
            }
            $body['start'] = $start;
            $body['end'] = $end;
        }

        return $body;
    }

    // A series' start or end, moved by as much as the repeat's moved.
    private function shift(array $masterTime, string $original, array $changed, bool $allDay): array
    {
        $zone = config('app.timezone');
        $was = Carbon::parse($original, $zone);
        $now = Carbon::parse($changed['dateTime'] ?? $changed['date'], $zone);
        $base = Carbon::parse($masterTime['dateTime'] ?? $masterTime['date'], $masterTime['timeZone'] ?? $zone);
        $moved = $base->copy()->addSeconds($was->diffInSeconds($now, false));

        return $allDay
            ? ['date' => $moved->toDateString(), 'dateTime' => null, 'timeZone' => null]
            : ['dateTime' => $moved->toIso8601String(), 'timeZone' => $masterTime['timeZone'] ?? $zone, 'date' => null];
    }

    // The guest list, keeping each existing guest's reply and details.
    private function withGuests(array $body, array $data, array $current): array
    {
        if (! array_key_exists('guests', $data)) {
            return $body;
        }
        $existing = collect($current['attendees'] ?? [])->keyBy(fn ($a) => strtolower($a['email']));
        $kept = $existing->filter(fn ($a) => ($a['self'] ?? false) || ($a['resource'] ?? false));
        $chosen = collect($data['guests'])->map(fn ($email) => $existing->get(strtolower($email), ['email' => $email]));
        $body['attendees'] = $kept->values()->merge($chosen)->unique(fn ($a) => strtolower($a['email']))->values()->all();

        return $body;
    }

    public function destroy(Request $request): array
    {
        if ($account = $request->user()->googleCalendarAccount) {
            $this->google->revoke($account);
            $account->delete();
        }

        return ['connected' => false];
    }

    private function stored(array $calendar): array
    {
        return collect($calendar)->only(['id', 'name', 'color', 'can_edit'])->all();
    }
}
