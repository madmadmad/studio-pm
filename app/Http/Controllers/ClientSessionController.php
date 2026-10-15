<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Keeping an open app healthy (lib/session.js). `ping`: the app checks in
// every few minutes and on waking, which keeps the session alive and
// refreshes the CSRF cookie -- signed out, it's a 401 and the app says so
// rather than failing quietly. `event`: what the app noticed going wrong
// (a request refused, the live connection dropping, a reload for a new
// deploy, a JavaScript error), written to the log so Cloud's logs show
// what happened to a window left open all day.
class ClientSessionController extends Controller
{
    public function ping(Request $request)
    {
        return response()->json(['user_id' => $request->user()->id]);
    }

    public function event(Request $request)
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'max:20'],
            'events.*.event' => ['required', 'string', 'max:60'],
            'events.*.detail' => ['nullable', 'array'],
            'events.*.at' => ['nullable', 'string', 'max:40'],
            'events.*.url' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['events'] as $event) {
            Log::warning('Client: '.$event['event'], [
                'user_id' => $request->user()->id,
                'at' => $event['at'] ?? null,
                'url' => $event['url'] ?? null,
                'detail' => array_slice($event['detail'] ?? [], 0, 20),
                'installed' => $request->boolean('installed'),
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);
        }

        return response()->noContent();
    }
}
