// Push-only worker: never caches authenticated pages or intercepts fetches.
// Safe to activate immediately: this worker owns no offline caches or fetch
// pipeline that could become incompatible with already-open backoffice pages.
self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        const payload = event.data?.json();
        if (!payload || typeof payload.title !== 'string' || typeof payload.body !== 'string') return;
        await self.registration.showNotification(payload.title, {
            body: payload.body,
            tag: `notification-${payload.id}`,
            icon: '/skeleton-logo.png',
            data: {url: payload.url},
        });
        const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
        windows.forEach((client) => client.postMessage({type: 'notification'}));
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil((async () => {
        const url = new URL(event.notification.data?.url || '/account/notifications', self.location.origin);
        if (url.origin !== self.location.origin) return;
        // Never replace an existing backoffice tab: it may contain an unsaved
        // form. Ask the browser to open a new browsing context instead.
        await self.clients.openWindow(url.href);
    })());
});
