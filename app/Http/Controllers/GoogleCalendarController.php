<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarAccount;
use App\Models\Project;
use App\Services\GoogleCalendarClient;
use App\Services\GoogleCalendarConflict;
use App\Services\GoogleCalendarDisconnected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        return $this->forForm($request, $account, $this->withProjects($request, $events));
    }

    // A project's upcoming meetings (its Schedule tab): the events linked
    // to it on the calendars this person shows, the next 10 over a year.
    // An event on two of their calendars (one they're a guest at) shows once.
    public function projectMeetings(Request $request, Project $project): array
    {
        $this->authorize('view', $project);
        $account = $request->user()->googleCalendarAccount;
        if (! $account) {
            return ['connected' => false, 'events' => []];
        }

        $calendars = $account->calendars ?? [];
        $from = now()->startOfDay();
        $key = 'google-calendar-project:'.$account->id.':'.md5(json_encode([$project->id, $from->toDateString(), collect($calendars)->pluck('id'), $account->updated_at]));

        try {
            $events = Cache::remember($key, now()->addMinutes(2), fn () => collect($calendars)
                ->flatMap(fn (array $calendar) => $this->google->events($account, $calendar, $from, $from->copy()->addYear(), $project->id))
                ->filter(fn (array $e) => ($e['all_day'] ? $e['end'] > $from->toDateString() : Carbon::parse($e['end'])->isFuture()))
                ->unique('id')
                ->sortBy(fn (array $e) => $e['all_day'] ? $e['start'].'T00:00:00' : Carbon::parse($e['start'])->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:s'))
                ->take(10)
                ->values()
                ->all());
        } catch (GoogleCalendarDisconnected) {
            return ['connected' => false, 'lost' => true, 'events' => []];
        } catch (\Throwable $e) {
            Log::warning('Google calendar project meetings failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return ['connected' => true, 'events' => [], 'error' => 'Meetings couldn’t be loaded from Google just now.'];
        }

        return $this->forForm($request, $account, $this->withProjects($request, $events));
    }

    // With the events, what the event form needs: the chosen calendars
    // (it offers the ones they can edit), the default one, and the
    // projects an event can be linked to.
    private function forForm(Request $request, GoogleCalendarAccount $account, array $events): array
    {
        return [
            'connected' => true,
            'events' => $events,
            'calendars' => array_values($account->calendars ?? []),
            'default_calendar_id' => $account->defaultCalendarId(),
            'projects' => Project::visibleTo($request->user())
                ->whereIn('status', ['estimated', 'active'])
                ->with('company:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'company_id'])
                ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'client' => $p->company?->name]),
        ];
    }

    // Each linked event's project, by name, for those who can open it.
    private function withProjects(Request $request, array $events): array
    {
        $ids = collect($events)->pluck('project_id')->filter()->unique();
        $projects = $ids->isEmpty() ? collect() : Project::visibleTo($request->user(), current: false)
            ->with('company:id,name')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'company_id'])
            ->keyBy('id');

        return collect($events)->map(function (array $e) use ($projects) {
            $p = $projects->get($e['project_id']);

            return $e + ['project' => $p ? ['id' => $p->id, 'name' => $p->name, 'client' => $p->company?->name] : null];
        })->all();
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
        $this->checkProject($request, $data);

        $body = $this->withGuests($this->body($data), $data, []);
        if (empty($data['project_id'])) {
            unset($body['extendedProperties']); // nothing to take off a new event
        }
        $created = $this->google->createEvent($account, $calendar['id'], $body, (bool) ($data['add_meet'] ?? false));
        $account->touch(); // the Agenda's cache moves on

        return ['event' => $this->google->event($created, $calendar)];
    }

    // Changes to an event: only the fields sent. A repeat changes just
    // itself (scope=this), the whole series (scope=all), which moves by as
    // much as this one did, or this one and the rest (scope=following),
    // which split off as a series of their own.
    public function updateEvent(Request $request, string $event): array
    {
        $account = $this->account($request);
        $data = $request->validate([
            'calendar_id' => ['required', 'string'],
            'etag' => ['nullable', 'string'],
            'scope' => ['nullable', 'in:this,following,all'],
            'recurring_event_id' => ['nullable', 'string'],
            'split_at' => ['nullable', 'required_if:scope,following', 'string'],
            'original_start' => ['nullable', 'required_if:scope,following', 'string'],
            'original_end' => ['nullable', 'required_if:scope,following', 'string'],
            ...$this->eventRules(required: false),
        ]);
        $calendar = $this->editableCalendar($account, $data['calendar_id']);
        $this->checkProject($request, $data);
        $scope = filled($data['recurring_event_id'] ?? null) ? ($data['scope'] ?? 'this') : 'this';

        try {
            $master =$scope === 'this' ? null : $this->google->getEvent($account, $calendar['id'], $data['recurring_event_id']);
            // From the first repeat on is the whole series.
            $before = $scope === 'following' ? $this->repeatsBefore($account, $calendar['id'], $master, $data) : 0;
            $series = $scope === 'all' || ($scope === 'following' && $before === 0);

            if ($scope === 'following' && $before > 0) {
                $updated = $this->splitSeries($account, $calendar['id'], $master, $before, $data);
            } elseif ($series) {
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
            'scope' => ['nullable', 'in:this,following,all'],
            'recurring_event_id' => ['nullable', 'string'],
            'split_at' => ['nullable', 'required_if:scope,following', 'string'],
        ]);
        $calendar = $this->editableCalendar($account, $data['calendar_id']);
        $scope = filled($data['recurring_event_id'] ?? null) ? ($data['scope'] ?? 'this') : 'this';

        try {
            if ($scope === 'following') {
                $master = $this->google->getEvent($account, $calendar['id'], $data['recurring_event_id']);
                $before = $this->repeatsBefore($account, $calendar['id'], $master, $data);
                // From the first repeat on is the whole series; otherwise
                // the series stops just before this one.
                $before === 0
                    ? $this->google->deleteEvent($account, $calendar['id'], $master['id'], $master['etag'] ?? null)
                    : $this->google->updateEvent($account, $calendar['id'], $master['id'],
                        ['recurrence' => $this->endedRecurrence($master, $data['split_at'])], $master['etag'] ?? null);
            } else {
                $series = $scope === 'all';
                $this->google->deleteEvent($account, $calendar['id'], $series ? $data['recurring_event_id'] : $event, $series ? null : ($data['etag'] ?? null));
            }
        } catch (GoogleCalendarConflict $e) {
            $account->touch();
            abort(409, $e->getMessage().' Here’s the latest; delete it again if you still want to.');
        }
        $account->touch();

        return ['deleted' => true];
    }

    /**
     * "This and following": the series stops just before this repeat, and
     * this one and the rest become a series of their own, a copy of the
     * original (guests and their replies, notes, reminders, video call)
     * with the form's changes. A series that ends after a number of
     * repeats is recounted so the two add up to the same. Should the new
     * series fail, the original gets its rule back.
     */
    private function splitSeries(GoogleCalendarAccount $account, string $calendarId, array $master, int $before, array $data): array
    {
        $zone = $master['start']['timeZone'] ?? config('app.timezone');
        $time = fn (string $at) => str_contains($at, 'T')
            ? ['dateTime' => Carbon::parse($at)->toIso8601String(), 'timeZone' => $zone]
            : ['date' => $at];

        $copy = collect($master)->only([
            'summary', 'description', 'location', 'attendees', 'reminders', 'colorId', 'transparency', 'visibility',
            'guestsCanModify', 'guestsCanInviteOthers', 'guestsCanSeeOtherGuests', 'conferenceData', 'extendedProperties',
        ])->all();
        unset($copy['conferenceData']['createRequest']);
        if (empty($copy['conferenceData'])) {
            unset($copy['conferenceData']);
        }
        $copy['start'] = $time($data['original_start']);
        $copy['end'] = $time($data['original_end']);
        $copy['recurrence'] = $this->rewriteRule($master['recurrence'] ?? [], function (array $parts, ?string $count) use ($before) {
            return $count === null ? $parts : [...$parts, 'COUNT='.max(1, (int) $count - $before)];
        });

        $body = $this->withGuests(array_merge($copy, $this->body(Arr::except($data, ['repeat']))), $data, $master);

        $this->google->updateEvent($account, $calendarId, $master['id'], ['recurrence' => $this->endedRecurrence($master, $data['split_at'])], $master['etag'] ?? null);
        try {
            return $this->google->createEvent($account, $calendarId, $body, (bool) ($data['add_meet'] ?? false));
        } catch (\Throwable $e) {
            $this->google->updateEvent($account, $calendarId, $master['id'], ['recurrence' => $master['recurrence'] ?? []]);
            throw $e;
        }
    }

    // How many repeats come before this one ($data['split_at'], its place in the series).
    private function repeatsBefore(GoogleCalendarAccount $account, string $calendarId, array $master, array $data): int
    {
        $at = $data['split_at'] ?? $data['original_start'];
        $before = str_contains($at, 'T')
            ? Carbon::parse($at)->toIso8601String()
            : Carbon::parse($at, $master['start']['timeZone'] ?? config('app.timezone'))->startOfDay()->toIso8601String();

        return $this->google->repeatsBefore($account, $calendarId, $master['id'], $before);
    }

    // The series' rule, stopping just before $splitAt: the day before for
    // all-day events, a second before in UTC for timed ones.
    private function endedRecurrence(array $master, string $splitAt): array
    {
        $until = isset($master['start']['date'])
            ? Carbon::parse($splitAt)->subDay()->format('Ymd')
            : Carbon::parse($splitAt)->subSecond()->utc()->format('Ymd\THis\Z');

        return $this->rewriteRule($master['recurrence'] ?? [], fn (array $parts) => [
            ...array_filter($parts, fn ($p) => ! str_starts_with($p, 'UNTIL=')),
            'UNTIL='.$until,
        ]);
    }

    /**
     * A series' recurrence lines with its RRULE's end changed: $change gets
     * the rule's parts without COUNT, plus the old COUNT (or null), and
     * returns the parts to keep (a rule has a COUNT or an UNTIL, never
     * both). Other lines (EXDATE, RDATE) stay as they are.
     */
    private function rewriteRule(array $recurrence, callable $change): array
    {
        return collect($recurrence)->map(function (string $line) use ($change) {
            if (! str_starts_with($line, 'RRULE:')) {
                return $line;
            }
            $parts = collect(explode(';', substr($line, 6)));
            $count = $parts->first(fn ($p) => str_starts_with($p, 'COUNT='));
            $rest = $parts->reject(fn ($p) => str_starts_with($p, 'COUNT='))->values()->all();
            return 'RRULE:'.implode(';', $change($rest, $count ? substr($count, 6) : null));
        })->values()->all();
    }

    private function account(Request $request): GoogleCalendarAccount
    {
        $account = $request->user()->googleCalendarAccount;
        abort_unless($account, 422, 'Connect your Google Calendar from your profile first.');

        return $account;
    }

    // An event can be linked only to a project they work on.
    private function checkProject(Request $request, array $data): void
    {
        if (! empty($data['project_id'])) {
            abort_unless(Project::visibleTo($request->user())->whereKey($data['project_id'])->exists(), 403, 'You can’t link events to that project.');
        }
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
            'start_date' => [$need, 'required_with:repeat', 'date_format:Y-m-d'],
            'end_date' => [$need, 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'required_if:all_day,false', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_if:all_day,false', 'date_format:H:i'],
            'location' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'guests' => ['sometimes', 'array', 'max:100'],
            'guests.*' => ['email'],
            'add_meet' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            // How it repeats, worded from the start date (see recurrence()).
            'repeat' => ['sometimes', 'in:none,daily,weekdays,weekly,biweekly,monthly_day,monthly_weekday,yearly'],
            'repeat_ends' => ['nullable', 'in:never,on,after'],
            'repeat_until' => ['nullable', 'required_if:repeat_ends,on', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'repeat_count' => ['nullable', 'required_if:repeat_ends,after', 'integer', 'min:2', 'max:500'],
        ];
    }

    /**
     * Google's repeat rule for the form's choice, from the start date: the
     * day of the week, the day of the month or the date of the year come
     * from it, so moving a series later keeps it repeating the same way.
     * "Monthly on the nth weekday" uses "last" for a fifth one, which not
     * every month has. An empty list: it doesn't repeat.
     */
    private function recurrence(array $data): array
    {
        $repeat = $data['repeat'] ?? 'none';
        if ($repeat === 'none') {
            return [];
        }

        $start = Carbon::parse($data['start_date']);
        $day = strtoupper(substr($start->englishDayOfWeek, 0, 2));
        $nth = intdiv($start->day - 1, 7) + 1;

        $rule = match ($repeat) {
            'daily' => 'FREQ=DAILY',
            'weekdays' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
            'weekly' => 'FREQ=WEEKLY',
            'biweekly' => 'FREQ=WEEKLY;INTERVAL=2',
            'monthly_day' => 'FREQ=MONTHLY',
            'monthly_weekday' => 'FREQ=MONTHLY;BYDAY='.($nth === 5 ? -1 : $nth).$day,
            'yearly' => 'FREQ=YEARLY',
        };

        // Through the end of the last day: a date for all-day events, the
        // moment in UTC for timed ones (Google's rule for UNTIL).
        $rule .= match ($data['repeat_ends'] ?? 'never') {
            'on' => ';UNTIL='.($data['all_day']
                ? Carbon::parse($data['repeat_until'])->format('Ymd')
                : Carbon::parse($data['repeat_until'], config('app.timezone'))->endOfDay()->utc()->format('Ymd\THis\Z')),
            'after' => ';COUNT='.(int) $data['repeat_count'],
            default => '',
        };

        return ['RRULE:'.$rule];
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

        // The project it's for (null takes the link off).
        if (array_key_exists('project_id', $data)) {
            $body['extendedProperties']['shared'][GoogleCalendarClient::PROJECT_KEY] = $data['project_id'] ? (string) $data['project_id'] : null;
        }

        // Only one-off events get a repeat here; a series keeps Google's rule.
        if (array_key_exists('repeat', $data) && ! $master) {
            $body['recurrence'] = $this->recurrence($data);
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
