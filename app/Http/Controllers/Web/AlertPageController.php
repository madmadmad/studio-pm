<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// The Alerts page: the signed-in person's own notifications (Laravel's
// database channel) -- a client opening an invoice or proposal, a failed or
// skipped invoice send, the reconciliation and sales-tax reminders. The nav
// item's count is HandleInertiaRequests' alertsUnread.
class AlertPageController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Alerts/Index', [
            'notifications' => $request->user()->notifications()->latest()->limit(100)->get(),
        ]);
    }
}
