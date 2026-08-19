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

// Rewritten by scripts/embed-offline-shell.mjs on every build.
//
// It was a hardcoded 'v1', so the activate handler that deletes old caches
// never had a different key to delete and nothing was ever evicted. Assets are
// content-hashed, which usually makes that harmless — but a stale stylesheet
// kept serving alongside fresh HTML, so a page referencing new class names was
// styled by a sheet that did not have them. The header disappeared.
const VERSION = 'ad81c489176b';
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
    //
    // The downloads screen is the exception. It lists what is stored on this
    // device and is needed precisely when there is no connection, so its shell
    // is cached and served when the network is gone. It contains no library
    // data — the list is read from IndexedDB — so a stale copy is harmless.
    if (request.mode === 'navigate') {
        const isDownloadsPage = url.pathname === '/app/downloads';

        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (isDownloadsPage && response.ok) {
                        const copy = response.clone();
                        caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => (isDownloadsPage
                    ? caches.match(request).then((hit) => hit ?? caches.match(OFFLINE_URL))
                    : caches.match(OFFLINE_URL))),
        );

        return;
    }

    if (isCacheableAsset(url)) {
        // Build output is content-hashed, so the cache can only ever hold the
        // bytes that URL has always had — revalidating it buys nothing, and
        // serving it stale-first is what let an old stylesheet outlive its
        // build. Network-first with a cache fallback keeps it available
        // offline without letting it win while the server is reachable.
        event.respondWith(
            url.pathname.startsWith('/build/')
                ? networkFirst(request)
                : staleWhileRevalidate(request),
        );
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
/**
 * The network's answer, falling back to whatever was cached.
 *
 * For content-hashed assets this is strictly better than serving stale: the
 * bytes behind a given URL never change, so there is nothing to gain by
 * answering from cache first, and a cache that outlives its build actively
 * breaks the page it styles.
 */
async function networkFirst(request) {
    const cache = await caches.open(ASSET_CACHE);

    try {
        const response = await fetch(request);

        if (response.ok && response.type === 'basic') {
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request);

        // Rethrowing rather than returning undefined: a failed asset fetch with
        // nothing cached must surface as a failed request, not an empty 200.
        if (!cached) throw error;

        return cached;
    }
}

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
