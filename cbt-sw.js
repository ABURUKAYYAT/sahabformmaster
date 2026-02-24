const CBT_CACHE = 'cbt-offline-cache-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((key) => key !== CBT_CACHE)
                .map((key) => caches.delete(key))
        );
        await self.clients.claim();
    })());
});

function isCbtNavigationRequest(request, url) {
    if (request.mode !== 'navigate') {
        return false;
    }
    return /\/(student|teacher)\/cbt(_tests|_take|_results|_submit)?\.php$/i.test(url.pathname);
}

function isStaticAsset(request, url) {
    if (['style', 'script', 'font', 'image'].includes(request.destination)) {
        return true;
    }
    return url.pathname.includes('/assets/');
}

async function networkFirst(request) {
    const cache = await caches.open(CBT_CACHE);
    try {
        const networkResponse = await fetch(request);
        if (networkResponse && networkResponse.ok) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        return new Response(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Offline</title></head><body><h3>Offline</h3><p>CBT page is not available offline yet. Reconnect and try again.</p></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
        );
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(CBT_CACHE);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }

    try {
        const networkResponse = await fetch(request);
        if (networkResponse && networkResponse.ok) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        return cached || Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (isCbtNavigationRequest(request, url)) {
        event.respondWith(networkFirst(request));
        return;
    }

    if (isStaticAsset(request, url)) {
        event.respondWith(cacheFirst(request));
    }
});
