const cacheName = 'tempo-public-v1';
const offline = '/offline.html';

self.addEventListener('install', event => {
    event.waitUntil(caches.open(cacheName).then(cache => cache.addAll([offline, '/tempo.png', '/tempo-large.png'])));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(
        keys.filter(key => key.startsWith('tempo-public-') && key !== cacheName).map(key => caches.delete(key)),
    )));
});

self.addEventListener('fetch', event => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') return;
    event.respondWith(fetch(event.request).catch(async () => (await caches.match(offline)) ?? Response.error()));
});
