<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarAccount;
use App\Services\GoogleCalendarClient;
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

        return ['chosen' => $data['ids']];
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

        return ['connected' => true, 'events' => $events, 'calendars' => count($calendars)];
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
