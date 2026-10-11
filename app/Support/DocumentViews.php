<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\DocumentOpened;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// The client opening an invoice's or proposal's link (/i/..., /p/...),
// as reported by the page itself once it has loaded in a browser -- so a
// mail scanner following the link on delivery, which doesn't run the
// page, isn't counted. Signed-in staff (checking the link, previewing the
// portal) aren't counted either, and one browser counts once per half
// hour. The first open tells whoever sent it, unless they've turned that
// off (Profile).
class DocumentViews
{
    private const REPEAT_MINUTES = 30;

    public static function record(Request $request, Invoice|Proposal $document): void
    {
        if (auth('web')->check()) {
            return;
        }

        $key = 'document_viewed.'.$document->getMorphClass().'.'.$document->id;
        $last = $request->session()->get($key);
        if ($last && $last > now()->subMinutes(self::REPEAT_MINUTES)->getTimestamp()) {
            return;
        }
        $request->session()->put($key, now()->getTimestamp());

        $now = now()->utc()->format('Y-m-d H:i:s');
        $query = $document->newQuery()->whereKey($document->id)->toBase();

        // Only one request can be the first, however close two are.
        $first = (clone $query)->whereNull('first_viewed_at')->update(['first_viewed_at' => $now]) === 1;
        $query->update(['last_viewed_at' => $now, 'view_count' => DB::raw('view_count + 1')]);

        if ($first) {
            Notification::send(self::whoToTell($document), new DocumentOpened($document));
        }
    }

    // Whoever sent it; when that's not known (imported, or sent before
    // this was recorded), everyone who looks after invoices or proposals.
    private static function whoToTell(Invoice|Proposal $document): Collection
    {
        $sender = $document instanceof Invoice
            ? $document->invoiceSends()->whereIn('type', ['email', 'link'])->where('status', 'sent')->whereNotNull('sent_by_user_id')->latest('sent_at')->first()?->sentBy
            : $document->sentBy;

        $people = $sender ? collect([$sender]) : User::withPermission($document instanceof Invoice ? 'invoices' : 'proposals');

        return $people->filter(fn (User $user) => $user->isActive() && $user->isStaff() && $user->notify_on_document_open)->values();
    }
}
