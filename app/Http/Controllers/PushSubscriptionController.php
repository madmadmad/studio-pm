<?php

namespace App\Http\Controllers;

use App\Notifications\PushAlert;
use Illuminate\Http\Request;

// Notifications on one of the person's devices (Profile → Notifications):
// the browser's push subscription, saved when they turn them on there and
// removed when they turn them off. A device that stops accepting pushes
// is dropped by the push channel on its own.
class PushSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
        );

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['subscribed' => false]);
    }

    // "Send a test": straight away (not queued), to every device they've
    // turned on, so they can see it arrive.
    public function test(Request $request)
    {
        $user = $request->user();
        abort_unless($user->pushSubscriptions()->exists(), 422, 'Turn on notifications on this device first.');

        $user->notifyNow(new PushAlert('Notifications are on', 'This is how studio alerts will show up on this device.', '/my-work', 'test'));

        return response()->noContent();
    }
}
