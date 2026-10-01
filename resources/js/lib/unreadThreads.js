import { useState } from 'react';
import { api } from './api';

// Unread message threads on a project page (App\\Services\\UnreadMessages):
// each thread arrives with `unread`; opening one marks it read on the
// server and here at once, so the dot and the tab's count clear without
// a reload. `readUrl(id)` is the staff or portal read endpoint. A refused
// mark (a staff preview of the portal is read-only) just leaves it as is
// on the server -- it still clears here for the visit.
export function useUnreadThreads(threads, readUrl) {
    const [readIds, setReadIds] = useState([]);
    const isUnread = (thread) => Boolean(thread.unread) && !readIds.includes(thread.id);

    function markRead(thread) {
        if (!isUnread(thread)) return;
        setReadIds((ids) => [...ids, thread.id]);
        api.post(readUrl(thread.id)).catch(() => {});
    }

    return { isUnread, markRead, count: threads.filter(isUnread).length };
}
