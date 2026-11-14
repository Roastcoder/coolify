// Fork-only: StandaloneCoders Cloud service worker. Pages always come from the network so server
// status is never stale; only the offline page and its icon are cached, for when the network is gone.
const CACHE = 'sc-offline-v1';
const OFFLINE_ASSETS = ['/offline.html', '/standalonecoders-mark.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(OFFLINE_ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
});
