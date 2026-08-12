/**
 * SoundChex service worker.
 *
 * Deliberately conservative. A media library is mostly authenticated, dynamic
 * HTML plus large range-requested audio, and caching either of those wrongly
 * causes worse problems than being offline does:
 *
 *   - HTML is never cached, so a stale page can't hide a library that changed.
 *   - Audio is never cached; range requests and opaque partial responses do
 *     not survive the Cache API cleanly.
 *   - Only build assets and artwork are cached, both of which are immutable
 *     or cheap to refresh.
 */

const VERSION = 'v1';
const ASSET_CACHE = `soundchex-assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(ASSET_CACHE)
            .then((cache) => cache.add(OFFLINE_URL))
            // A missing offline page must not block installation.
            .catch(() => undefined)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('soundchex-') && key !== ASSET_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only GET is cacheable, and cross-origin requests are left alone.
    if (request.method !== 'GET' || !request.url.startsWith(self.location.origin)) {
        return;
    }

    const url = new URL(request.url);

    // Range requests are audio seeking — always go to the network.
    if (request.headers.has('range') || url.pathname.includes('/stream')) {
        return;
    }

    // Navigations: network-first, falling back to the offline page. Never
    // serve a cached shell, which would show a stale or wrongly-authed view.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );

        return;
    }

    if (isCacheableAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
    }
});

/**
 * Build output is content-hashed, and artwork is effectively immutable once
 * written. Everything else (API responses, authenticated pages) is skipped.
 */
function isCacheableAsset(url) {
    return url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/storage/artwork/')
        || /\.(?:css|js|woff2?|png|jpe?g|svg|webp|ico)$/i.test(url.pathname);
}

/**
 * Serve from cache immediately when present, and refresh in the background so
 * the next load is current.
 */
async function staleWhileRevalidate(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            // Opaque and error responses are not worth persisting.
            if (response.ok && response.type === 'basic') {
                cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => cached);

    return cached || network;
}
