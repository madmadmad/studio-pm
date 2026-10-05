import { useState } from 'react';
import { api } from './api';

// Unread message threads on a project page (App\\Services\\UnreadMessages):
// each thread arrives with `unread`; opening one marks it read on the
// server and here at once, so the dot and the tab's count clear without
// a reload. `readUrl(id)` is the staff or portal read endpoint. A refused
// mark (a staff preview of the portal is read-only) just leaves it as is
// on the server -- it still clears here for the visit.
// A thread's latest activity: its newest message (thread or reply).
function latestActivity(thread) {
    return [thread, ...(thread.replies || [])].map((m) => m.sent_at || '').sort().pop();
}

export function useUnreadThreads(threads, readUrl) {
    // What's been read here, by how far: thread id -> its latest message
    // when read. A reply arriving later (the Messages tab checks for new
    // ones) makes it unread again.
    const [readUpTo, setReadUpTo] = useState({});
    const isUnread = (thread) => Boolean(thread.unread) && readUpTo[thread.id] !== latestActivity(thread);

    function markRead(thread) {
        if (!isUnread(thread)) return;
        setReadUpTo((read) => ({ ...read, [thread.id]: latestActivity(thread) }));
        api.post(readUrl(thread.id)).catch(() => {});
    }

    return { isUnread, markRead, count: threads.filter(isUnread).length };
}
