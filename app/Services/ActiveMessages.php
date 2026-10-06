<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

// "Active messages" on the Client Hub's home: conversations with something
// new in the last WINDOW_DAYS days -- the thread's first message or a reply,
// from anyone, not deleted. Counted per thread, not per message, and like
// UnreadMessages only threads this contact is on, on their company's
// projects. Read or not doesn't matter: it's how much is going on, beside
// the unread counts on each project.
class ActiveMessages
{
    const WINDOW_DAYS = 7;

    public static function count(Contact $contact): int
    {
        return DB::table('messages as t')
            ->join('projects as p', 'p.id', '=', 't.project_id')
            ->join('message_participants as mp', fn ($join) => $join->on('mp.message_id', '=', 't.id')->where('mp.contact_id', $contact->id))
            ->where('p.company_id', $contact->company_id)
            ->whereNull('t.parent_id')
            ->whereNull('t.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('messages as m')
                ->whereNull('m.deleted_at')
                ->where(fn ($w) => $w->whereColumn('m.id', 't.id')->orWhereColumn('m.parent_id', 't.id'))
                ->where('m.sent_at', '>=', now()->subDays(self::WINDOW_DAYS)))
            ->count();
    }
}
