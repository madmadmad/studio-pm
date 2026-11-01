<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Marks alerts read from the Alerts page (Web\AlertPageController lists
// them): one at a time, or all at once. Scoped to the authenticated user's
// own notifications only, never another user's.
class NotificationController extends Controller
{
    public function markRead(Request $request, string $notification)
    {
        $model = $request->user()->notifications()->findOrFail($notification);
        $model->markAsRead();

        return $model;
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
