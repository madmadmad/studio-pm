import { useSyncExternalStore } from 'react';

// Keeping an app that stays open all day healthy -- a Dock app on a Mac
// that sleeps, a tab left over lunch. The session lasts two hours without
// a request, so:
//
// - It checks in (/api/session) every few minutes while open, and as soon
//   as the Mac wakes or the window comes back, which keeps the session
//   alive and refreshes the CSRF cookie.
// - A request refused for a stale CSRF token is retried once after a
//   check-in (api.js); a real sign-out shows "Sign in again"
//   (SignedOutNotice) instead of saves failing quietly.
// - What it notices going wrong -- a refused request, the live connection
//   dropping, a reload for a new deploy, a JavaScript error -- is reported
//   to the server's log (report()), queued in the browser until it can be
//   sent, so Cloud's logs show what happened to a window left open.
//
// Started once per tab by AppLayout. Plain fetch, not api.js, which calls
// in here.

const CHECK_EVERY_MS = 5 * 60 * 1000;
const TICK_MS = 60 * 1000;
// A tick this late means the computer was asleep.
const SLEPT_AFTER_MS = 3 * TICK_MS;
const QUEUE_KEY = 'studio:client-events';
// The same event at most once a minute, so a storm stays one line.
const REPORT_EVERY_MS = 60 * 1000;

let signedOut = false;
const listeners = new Set();
const wakeListeners = new Set();
let started = false;
let lastCheck = 0;
const lastReported = {};

function setSignedOut(value) {
    if (signedOut === value) return;
    signedOut = value;
    listeners.forEach((listener) => listener());
}

export function useSignedOut() {
    return useSyncExternalStore((listener) => {
        listeners.add(listener);
        return () => listeners.delete(listener);
    }, () => signedOut);
}

export function isSignedOut() {
    return signedOut;
}

// Only the staff app checks in (the client portal has no /api/session).
export function sessionStarted() {
    return started;
}

// Runs when the computer wakes or the window comes back (chat reconnects).
export function onWake(fn) {
    wakeListeners.add(fn);
    return () => wakeListeners.delete(fn);
}

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
}

// 'ok', 'signed-out', or 'offline' (no answer -- the network's down).
export async function checkSession() {
    lastCheck = Date.now();
    try {
        const response = await fetch('/api/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (response.status === 401 || response.status === 419) {
            if (!signedOut) report('signed_out', { status: response.status });
            setSignedOut(true);
            return 'signed-out';
        }
        if (response.ok) {
            setSignedOut(false);
            flush();
            return 'ok';
        }
        return 'offline';
    } catch {
        return 'offline';
    }
}

// Something worth knowing about, for the server's log.
export function report(event, detail = {}) {
    const now = Date.now();
    if (lastReported[event] && now - lastReported[event] < REPORT_EVERY_MS) return;
    lastReported[event] = now;

    const queue = readQueue();
    queue.push({ event, detail, at: new Date(now).toISOString(), url: window.location.pathname });
    writeQueue(queue.slice(-20));
    flush();
}

let flushing = false;

async function flush() {
    if (flushing || signedOut) return;
    const queue = readQueue();
    if (!queue.length) return;

    flushing = true;
    try {
        const token = readCookie('XSRF-TOKEN');
        const response = await fetch('/api/session/events', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'X-XSRF-TOKEN': token } : {}) },
            credentials: 'same-origin',
            body: JSON.stringify({ events: queue, installed: isInstalled() }),
        });
        // Sent (or refused as malformed): either way, don't send it again.
        if (response.ok || response.status === 422) writeQueue(readQueue().slice(queue.length));
    } catch {
        // Kept for next time.
    } finally {
        flushing = false;
    }
}

function readQueue() {
    try {
        return JSON.parse(window.localStorage.getItem(QUEUE_KEY)) || [];
    } catch {
        return [];
    }
}

function writeQueue(queue) {
    try {
        window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch {
        // Not kept; the report is lost, nothing else.
    }
}

function isInstalled() {
    return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

let waking = null;

function wake(reason, detail = {}) {
    if (waking) return;
    waking = setTimeout(() => { waking = null; }, 5000);
    if (reason === 'slept') report('woke_from_sleep', detail);
    checkSession();
    wakeListeners.forEach((fn) => fn());
}

export function startSession() {
    if (started) return;
    started = true;
    lastCheck = Date.now();
    flush();

    let lastTick = Date.now();
    setInterval(() => {
        const now = Date.now();
        if (now - lastTick > SLEPT_AFTER_MS) {
            wake('slept', { minutes: Math.round((now - lastTick) / 60000) });
        } else if (now - lastCheck > CHECK_EVERY_MS && !document.hidden) {
            checkSession();
        }
        lastTick = now;
    }, TICK_MS);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && Date.now() - lastCheck > TICK_MS) wake('visible');
    });
    window.addEventListener('focus', () => {
        if (Date.now() - lastCheck > TICK_MS) wake('focus');
    });
    window.addEventListener('online', () => wake('online'));

    window.addEventListener('error', (event) => report('js_error', { message: String(event.message).slice(0, 300), source: `${event.filename}:${event.lineno}` }));
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        // A refused request is reported where it happens (api.js).
        if (reason?.status) return;
        report('js_error', { message: String(reason?.message ?? reason).slice(0, 300) });
    });
}
