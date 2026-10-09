import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { api } from './api';

// The one WebSocket connection to Reverb, for Chat. Made on first use and
// kept for the life of the tab -- Inertia page changes don't drop it. Its
// settings are the VITE_REVERB_* variables, baked in at build time (Forge:
// the site's environment; Laravel Cloud: injected by its WebSocket
// cluster). Without them Chat still works, just without live updates: it
// catches up whenever the tab comes back into view.
let instance = null;

export function echoAvailable() {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}

export function echo() {
    if (instance || !echoAvailable()) return instance;

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80);

    instance = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // Channel sign-in through the app's own request helper, so it
        // carries the session cookie and CSRF token like every other call.
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                api.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                    .then((data) => callback(null, data))
                    .catch((error) => callback(error, null));
            },
        }),
    });

    return instance;
}

// Calls `onChange(state)` as the connection goes 'connecting', 'connected',
// 'unavailable', 'disconnected'...; returns an unsubscribe.
export function onConnectionChange(onChange) {
    const connection = echo()?.connector?.pusher?.connection;
    if (!connection) return () => {};

    const handler = ({ current }) => onChange(current);
    connection.bind('state_change', handler);
    return () => connection.unbind('state_change', handler);
}
