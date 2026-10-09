import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from './api';
import { echo } from './echo';
import { onReconnect, useChat } from './chatStore';

const TYPING_SEND_EVERY_MS = 3000; // tell the others at most this often...
const TYPING_SHOWN_FOR_MS = 5000; // ...and they show it this long after the last

// Real messages in id order, then the ones still sending, in the order
// they were written.
function sorted(list) {
    const real = list.filter((m) => !m.pending).sort((a, b) => a.id - b.id);
    return [...real, ...list.filter((m) => m.pending)];
}

// Puts a message from the server in place: over its optimistic copy (same
// client_id), over an older copy of itself (same id), or in as new.
function upsert(list, message) {
    const next = list.filter((m) => !(m.pending && message.client_id && m.client_id === message.client_id));
    const index = next.findIndex((m) => m.id === message.id);
    if (index >= 0) next[index] = message;
    else next.push(message);
    return sorted(next);
}

// A client id for an optimistic message. crypto.randomUUID only exists on
// https (and localhost) -- a plain-http dev site gets the fallback.
function newClientId() {
    return crypto.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

function mergeAll(list, messages) {
    return messages.reduce(upsert, list);
}

// An open conversation's messages: the latest page on open, older pages on
// request, and everything after that as it happens -- over the socket, and
// by catching up from the API whenever the socket comes back, the tab comes
// back into view, or (with no socket) every so often. The API is the
// record; the socket only makes it quick.
export function useChatConversation(conversationId, me) {
    const [messages, setMessages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [hasMore, setHasMore] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [lastReadId, setLastReadId] = useState(null);
    const [typing, setTyping] = useState({}); // user id -> name
    const [error, setError] = useState('');
    const connected = useChat((s) => s.connected);

    const base = `/api/chat/conversations/${conversationId}/messages`;
    const syncedAt = useRef(null);
    const messagesRef = useRef(messages);
    messagesRef.current = messages;
    const typingTimers = useRef({});
    const lastTypingSent = useRef(0);
    const pendingFiles = useRef({}); // client_id -> files, for a retry

    const newestId = useCallback(() => {
        const real = messagesRef.current.filter((m) => !m.pending);
        return real.length ? real[real.length - 1].id : 0;
    }, []);

    const loadLatest = useCallback(async () => {
        const data = await api.get(base);
        syncedAt.current = data.synced_at;
        setMessages((list) => sorted([...data.messages, ...list.filter((m) => m.pending)]));
        setHasMore(data.has_more);
        setLastReadId(data.last_read_message_id);
    }, [base]);

    // What was missed: newer messages, and older ones changed since the
    // last sync. Too far behind to patch up, start again from the latest.
    const catchUp = useCallback(async () => {
        if (!syncedAt.current) return;
        try {
            const data = await api.get(`${base}?after=${newestId()}&since=${encodeURIComponent(syncedAt.current)}`);
            if (data.has_more) {
                await loadLatest();
                return;
            }
            syncedAt.current = data.synced_at;
            setMessages((list) => mergeAll(list, data.messages));
        } catch {
            // Offline -- the next reconnect or focus tries again.
        }
    }, [base, newestId, loadLatest]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError('');
        loadLatest()
            .catch((e) => !cancelled && setError(e.message || 'Could not load messages.'))
            .finally(() => !cancelled && setLoading(false));
        return () => { cancelled = true; };
    }, [loadLatest]);

    const loadOlder = useCallback(async () => {
        const oldest = messagesRef.current.find((m) => !m.pending);
        if (!oldest || loadingOlder || !hasMore) return false;
        setLoadingOlder(true);
        try {
            const data = await api.get(`${base}?before=${oldest.id}`);
            setMessages((list) => mergeAll(list, data.messages));
            setHasMore(data.has_more);
            return true;
        } finally {
            setLoadingOlder(false);
        }
    }, [base, hasMore, loadingOlder]);

    // Live: new and changed messages, and who's typing.
    useEffect(() => {
        const client = echo();
        if (!client) return undefined;
        const name = `conversation.${conversationId}`;

        client.private(name)
            .listen('.message.posted', ({ message }) => {
                setMessages((list) => upsert(list, message));
                stopTyping(message.user?.id);
            })
            .listen('.message.changed', ({ message }) => setMessages((list) => upsert(list, message)))
            .listenForWhisper('typing', ({ id, name: who }) => {
                if (id === me.id) return;
                setTyping((t) => ({ ...t, [id]: who }));
                clearTimeout(typingTimers.current[id]);
                typingTimers.current[id] = setTimeout(() => stopTyping(id), TYPING_SHOWN_FOR_MS);
            });

        return () => {
            client.leave(name);
            Object.values(typingTimers.current).forEach(clearTimeout);
            typingTimers.current = {};
            setTyping({});
        };
    }, [conversationId, me.id]);

    function stopTyping(id) {
        if (id == null) return;
        clearTimeout(typingTimers.current[id]);
        setTyping((t) => {
            if (!(id in t)) return t;
            const { [id]: _, ...rest } = t;
            return rest;
        });
    }

    // Catch up when the socket returns or the tab comes back into view;
    // with no socket at all, every 15 seconds while the tab's in view.
    useEffect(() => {
        const offReconnect = onReconnect(catchUp);
        const onVisible = () => !document.hidden && catchUp();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);
        return () => {
            offReconnect();
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
        };
    }, [catchUp]);

    useEffect(() => {
        if (connected) return undefined;
        const timer = setInterval(() => !document.hidden && catchUp(), 15_000);
        return () => clearInterval(timer);
    }, [connected, catchUp]);

    // Let the others know you're typing -- a client whisper, never stored.
    const notifyTyping = useCallback(() => {
        const now = Date.now();
        if (now - lastTypingSent.current < TYPING_SEND_EVERY_MS) return;
        lastTypingSent.current = now;
        echo()?.private(`conversation.${conversationId}`).whisper('typing', { id: me.id, name: me.name });
    }, [conversationId, me.id, me.name]);

    // Shown at once (an optimistic copy with a client id), then swapped for
    // the saved message by whichever arrives first: the response or the
    // broadcast. A failure stays in the list to retry or discard.
    const send = useCallback(async ({ body, files = [] }, clientId = newClientId()) => {
        pendingFiles.current[clientId] = { body, files };
        lastTypingSent.current = 0;
        const optimistic = {
            id: `pending-${clientId}`,
            client_id: clientId,
            pending: true,
            failed: false,
            progress: files.length ? 0 : null,
            conversation_id: conversationId,
            user: { id: me.id, name: me.name, avatar_url: me.avatar_url },
            body: body || null,
            mention_ids: [],
            attachments: files.map((file, i) => ({ id: `${clientId}-${i}`, name: file.name, size: file.size, is_image: false })),
            reactions: [],
            edited_at: null,
            deleted: false,
            created_at: new Date().toISOString(),
        };
        setMessages((list) => sorted([...list.filter((m) => m.client_id !== clientId), optimistic]));

        const markProgress = (progress) => setMessages((list) => list.map((m) => (m.client_id === clientId && m.pending ? { ...m, progress } : m)));

        try {
            let saved;
            if (files.length) {
                const form = new FormData();
                if (body) form.append('body', body);
                form.append('client_id', clientId);
                files.forEach((file) => form.append('attachments[]', file));
                saved = await api.postFormWithProgress(base, form, markProgress);
            } else {
                saved = await api.post(base, { body, client_id: clientId });
            }
            delete pendingFiles.current[clientId];
            setMessages((list) => upsert(list, saved));
        } catch (e) {
            setMessages((list) => list.map((m) => (m.client_id === clientId && m.pending
                ? { ...m, failed: true, error: e.errors ? Object.values(e.errors).flat()[0] : e.message }
                : m)));
        }
    }, [base, conversationId, me]);

    const retry = useCallback((clientId) => {
        const payload = pendingFiles.current[clientId];
        if (payload) send(payload, clientId);
    }, [send]);

    const discard = useCallback((clientId) => {
        delete pendingFiles.current[clientId];
        setMessages((list) => list.filter((m) => m.client_id !== clientId));
    }, []);

    const replace = useCallback((message) => setMessages((list) => upsert(list, message)), []);

    return {
        messages, loading, error, hasMore, loadingOlder, lastReadId, typing,
        loadOlder, send, retry, discard, replace, notifyTyping,
    };
}
