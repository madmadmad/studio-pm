import { useSyncExternalStore } from 'react';
import { api } from './api';
import { echo, onConnectionChange } from './echo';

// Chat's live state that outlives any one page: who's online (the
// chat.presence channel), your unread and mention counts (refreshed when
// your own channel says something changed), and whether the socket is up.
// Started once by AppLayout and kept for the life of the tab -- each page
// mounts its own AppLayout, and leaving and rejoining presence on every
// navigation would flicker you offline for everyone else.
//
// The counts always come from the server (/api/chat/unread); a socket
// signal only says "ask again", so a missed one costs a moment's delay at
// most, never a wrong number.

let state = {
    started: false,
    connected: false,
    online: new Set(),
    counts: {},
    total: { unread: 0, mentions: 0 },
};
const listeners = new Set();
const reconnectListeners = new Set();

function set(patch) {
    state = { ...state, ...patch };
    listeners.forEach((listener) => listener());
}

function subscribe(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export function useChat(selector) {
    return useSyncExternalStore(subscribe, () => selector(state));
}

export function chatState() {
    return state;
}

let refreshing = null;
let refreshAgain = false;

// Your counts, fresh from the server. Calls that land while one's in
// flight fold into a single follow-up.
export function refreshUnread() {
    if (refreshing) {
        refreshAgain = true;
        return refreshing;
    }
    refreshing = api.get('/api/chat/unread')
        .then(({ conversations, total }) => set({ counts: conversations ?? {}, total }))
        .catch(() => {})
        .finally(() => {
            refreshing = null;
            if (refreshAgain) {
                refreshAgain = false;
                refreshUnread();
            }
        });
    return refreshing;
}

// Set straight from a response that already carries them (marking read),
// saving a round trip.
export function setConversationCounts(conversationId, counts) {
    const next = { ...state.counts };
    if (counts.unread > 0 || counts.mentions > 0) next[conversationId] = counts;
    else delete next[conversationId];

    const values = Object.values(next);
    set({
        counts: next,
        total: {
            unread: values.reduce((sum, c) => sum + c.unread, 0),
            mentions: values.reduce((sum, c) => sum + c.mentions, 0),
        },
    });
}

// Runs `fn` whenever the socket comes back after dropping, so an open
// conversation can fetch what it missed. Returns an unsubscribe.
export function onReconnect(fn) {
    reconnectListeners.add(fn);
    return () => reconnectListeners.delete(fn);
}

// Something changed in a conversation you're in (a message posted, edited
// or deleted, or you read it in another tab). Open conversations listen
// for these too (ChatPage) -- to update the sidebar's order.
const activityListeners = new Set();
export function onActivity(fn) {
    activityListeners.add(fn);
    return () => activityListeners.delete(fn);
}

export function startChat(user, initialTotal) {
    if (state.started || !user) return;
    set({ started: true, total: initialTotal ?? state.total });

    // Counts on first load, and whenever the tab comes back into view
    // (a laptop waking, a backgrounded tab the browser throttled).
    refreshUnread();
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshUnread();
    });

    const client = echo();
    if (!client) return;

    // The first connect isn't a reconnect -- nothing was missed yet. Every
    // one after it is: catch up.
    let connectedBefore = false;
    onConnectionChange((current) => {
        const connected = current === 'connected';
        if (connected && connectedBefore) {
            refreshUnread();
            reconnectListeners.forEach((fn) => fn());
        }
        if (connected) connectedBefore = true;
        set({ connected });
    });

    client.join('chat.presence')
        .here((members) => set({ online: new Set(members.map((m) => m.id)) }))
        .joining((member) => set({ online: new Set([...state.online, member.id]) }))
        .leaving((member) => {
            const online = new Set(state.online);
            online.delete(member.id);
            set({ online });
        });

    client.private(`App.Models.User.${user.id}`)
        .listen('.chat.activity', (payload) => {
            refreshUnread();
            activityListeners.forEach((fn) => fn(payload));
        });
}
