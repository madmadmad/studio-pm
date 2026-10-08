<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Chat's unread counts: in each conversation someone's in, the messages
// from others after the last one they read (not deleted), and how many of
// those mention them. One query, for the sidebar and the nav badge.
class ChatUnread
{
    /**
     * @return array<int, array{unread: int, mentions: int}> keyed by conversation id, ones with nothing unread left out
     */
    public static function forUser(User $user): array
    {
        return DB::table('conversation_user as cu')
            ->join('chat_messages as m', fn ($join) => $join->on('m.conversation_id', '=', 'cu.conversation_id')
                ->whereRaw('m.id > coalesce(cu.last_read_message_id, 0)')
                ->whereNull('m.deleted_at'))
            ->leftJoin('chat_message_mentions as mm', fn ($join) => $join->on('mm.message_id', '=', 'm.id')->where('mm.user_id', $user->id))
            ->where('cu.user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->groupBy('cu.conversation_id')
            ->selectRaw('cu.conversation_id, count(m.id) as unread, count(mm.id) as mentions')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->conversation_id => ['unread' => (int) $row->unread, 'mentions' => (int) $row->mentions]])
            ->all();
    }

    // The nav badge: totals across every conversation.
    public static function totals(User $user): array
    {
        $counts = collect(static::forUser($user));

        return ['unread' => $counts->sum('unread'), 'mentions' => $counts->sum('mentions')];
    }
}
