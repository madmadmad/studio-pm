<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Unread messages, for staff and clients alike: a thread is unread for
// someone on it when a message from anyone else (the thread's first or a
// reply, not deleted) is newer than when they last read it -- their
// message_participants.last_read_at, set on opening the thread (and on
// posting to it, since writing is reading). Only threads you're on count:
// browsing into one you weren't included on doesn't make it "unread".
class UnreadMessages
{
    // Each thread's `unread` for this person, set on the loaded threads
    // (with Message::threadRelations(), so participants and replies are
    // there to read).
    public static function mark(Collection $threads, User|Contact $actor): Collection
    {
        return $threads->each(fn (Message $thread) => $thread->setAttribute('unread', static::isUnread($thread, $actor)));
    }

    public static function isUnread(Message $thread, User|Contact $actor): bool
    {
        $participant = $thread->participants->first(fn ($p) => $p->isActor($actor));
        if (! $participant) {
            return false;
        }

        $latestFromOthers = collect([$thread])->merge($thread->replies)
            ->reject(fn (Message $m) => $m->trashed() || $m->isSender($actor))
            ->max('sent_at');

        return $latestFromOthers !== null
            && ($participant->last_read_at === null || $latestFromOthers->gt($participant->last_read_at));
    }

    // How many unread threads this person has on each project, keyed by
    // project id (projects with none left out) -- one query, for the
    // project lists.
    public static function countsByProject(User|Contact $actor): array
    {
        [$participantColumn, $senderColumn] = $actor instanceof User
            ? ['user_id', 'sender_user_id']
            : ['contact_id', 'sender_contact_id'];

        return DB::table('messages as t')
            ->join('message_participants as mp', fn ($join) => $join->on('mp.message_id', '=', 't.id')->where("mp.{$participantColumn}", $actor->id))
            ->whereNull('t.parent_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('messages as m')
                ->whereNull('m.deleted_at')
                ->where(fn ($w) => $w->whereColumn('m.id', 't.id')->orWhereColumn('m.parent_id', 't.id'))
                ->where(fn ($w) => $w->whereNull("m.{$senderColumn}")->orWhere("m.{$senderColumn}", '!=', $actor->id))
                ->where(fn ($w) => $w->whereNull('mp.last_read_at')->orWhereColumn('m.sent_at', '>', 'mp.last_read_at')))
            ->groupBy('t.project_id')
            ->selectRaw('t.project_id, count(*) as unread')
            ->pluck('unread', 'project_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    // Marks the thread read for this person, if they're on it.
    public static function markRead(Message $thread, User|Contact $actor): void
    {
        $thread->participants()
            ->where($actor instanceof User ? 'user_id' : 'contact_id', $actor->id)
            ->update(['last_read_at' => now()]);
    }
}
