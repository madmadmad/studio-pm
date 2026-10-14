import { router } from '@inertiajs/react';
import { api } from './api';

// The staff app's service worker (public/sw.js) and notifications on this
// device (web push). Registered once per tab by AppLayout; a device is
// subscribed only when the person turns notifications on in Profile --
// browsers ask permission only after a click.

export const pushSupported = typeof window !== 'undefined'
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window;

// Running as the installed app (Safari's Add to Dock, a Home Screen icon,
// Chrome's Install) rather than a browser tab.
export const installed = typeof window !== 'undefined'
    && (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true);

let registration = null;

export function registerServiceWorker() {
    if (!pushSupported || registration) return registration;
    registration = navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => null);
    // A notification clicked while the app was open: go to its page here.
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'navigate' && event.data.url) router.visit(event.data.url);
    });
    return registration;
}

async function ready() {
    await registerServiceWorker();
    return navigator.serviceWorker.ready;
}

// This device's subscription, if notifications are on here.
export async function currentSubscription() {
    if (!pushSupported) return null;
    const reg = await ready();
    return reg.pushManager.getSubscription();
}

// Asks permission, subscribes this device, and saves it.
export async function turnOn(publicKey) {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error(permission === 'denied'
            ? 'Notifications are blocked for this site. Allow them in your browser or System Settings, then try again.'
            : 'Notifications weren’t allowed.');
    }
    const reg = await ready();
    let subscription;
    try {
        subscription = (await reg.pushManager.getSubscription())
            || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(publicKey) });
    } catch (err) {
        throw new Error(`This browser couldn’t set up notifications (${err.message}). Try Safari, or the app added to the Dock.`);
    }
    const json = subscription.toJSON();
    await api.post('/api/push-subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
    });
    return subscription;
}

export async function turnOff() {
    const subscription = await currentSubscription();
    if (!subscription) return;
    await api.delete('/api/push-subscriptions', { endpoint: subscription.endpoint });
    await subscription.unsubscribe();
}

// The number on the installed app's icon; nothing in a plain tab.
export function setBadge(count) {
    if (!navigator.setAppBadge) return;
    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
}

function base64UrlToBytes(value) {
    const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}
