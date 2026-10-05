<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageParticipant;
use App\Models\MessageReaction;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A fingerprint of a project's messages as one person sees them -- for
// the Messages tab's "anything new?" check every few seconds, so the page
// only refetches when a thread, reply, edit, deletion, reaction or new
// participant changed it. A few aggregate queries; nothing is loaded.
// (Read receipts are left out: someone else reading changes nothing here.)
class MessageVersion
{
    // `threads`: the project's threads this person can see (a client only
    // those they're on).
    public static function of(HasMany $threads): string
    {
        $threadIds = (clone $threads)->withTrashed()->pluck('id');
        $messages = Message::withTrashed()->where(fn ($q) => $q->whereIn('id', $threadIds)->orWhereIn('parent_id', $threadIds));

        $m = (clone $messages)->toBase()->selectRaw('count(*) as c, max(updated_at) as u, max(deleted_at) as d')->first();
        $messageIds = (clone $messages)->pluck('id');
        $r = MessageReaction::whereIn('message_id', $messageIds)->toBase()->selectRaw('count(*) as c, max(updated_at) as u')->first();
        $p = MessageParticipant::whereIn('message_id', $threadIds)->toBase()->selectRaw('count(*) as c, max(joined_at) as j')->first();

        return md5(json_encode([$m, $r, $p]));
    }
}
