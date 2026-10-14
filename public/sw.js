// The staff app's service worker: it runs in the background, so web push
// reaches the person with the app (or every tab) closed. Each push shows
// a notification and sets the Dock / Home Screen icon's badge; clicking a
// notification opens (or focuses) the app on the page it's about.
//
// A push's JSON (App\Notifications\PushAlert): title, body, icon, tag (one
// notification per conversation or record -- a newer one replaces it),
// and data: { url (the page it opens), count (the icon's badge) }.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: event.data?.text() };
    }

    const badge = Number.isInteger(data.data?.count) ? data.data.count : null;
    const setBadge = badge === null || !self.navigator.setAppBadge
        ? Promise.resolve()
        : (badge > 0 ? self.navigator.setAppBadge(badge) : self.navigator.clearAppBadge()).catch(() => {});

    // Safari and Chrome require every push to show a notification.
    event.waitUntil(Promise.all([
        setBadge,
        self.registration.showNotification(data.title || 'Madhouse Studio', {
            body: data.body || '',
            tag: data.tag || undefined,
            data: { url: data.data?.url || '/my-work' },
            icon: data.icon || '/icons/icon-192.png',
        }),
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/my-work', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((client) => client.url.startsWith(self.location.origin));
        if (open) {
            await open.focus();
            return open.navigate(url).catch(() => open.postMessage({ type: 'navigate', url }));
        }
        return self.clients.openWindow(url);
    })());
});
