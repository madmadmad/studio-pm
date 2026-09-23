<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// The small in-app inbox (bell icon) that surfaces failed/skipped invoice
// sends -- the only way to notice one of those for an invoice you aren't
// currently viewing. Scoped to the authenticated user's own notifications
// only, never another user's.
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return [
            'notifications' => $request->user()->notifications()->latest()->limit(30)->get(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ];
    }

    public function markRead(Request $request, string $notification)
    {
        $model = $request->user()->notifications()->findOrFail($notification);
        $model->markAsRead();

        return $model;
    }
}
