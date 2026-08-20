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
const VERSION = '70d3f31775f0';
const ASSET_CACHE = `soundchex-assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';

/**
 * Tells the page something happened in here.
 *
 * A service worker has no console anyone reads on a phone, so a navigation that
 * quietly fell back is invisible — which is exactly the failure being chased.
 * Posted to every open client, and stored so a page that arrives afterwards can
 * still find out why it is not the page that was asked for.
 */
const workerEvents = [];

function report(kind, detail) {
    const entry = { kind, detail, at: Date.now() };

    workerEvents.push(entry);

    if (workerEvents.length > 20) workerEvents.shift();

    self.clients.matchAll({ includeUncontrolled: true }).then((clients) => {
        clients.forEach((client) => client.postMessage({ soundchex: entry }));
    });
}

self.addEventListener('message', (event) => {
    if (event.data?.ask === 'soundchex:events') {
        event.source?.postMessage({ soundchexEvents: workerEvents });
    }
});
const PROBE_URL = '/offline-probe.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(ASSET_CACHE)
            // The probe is cached alongside the offline page: the app shell
            // frames it with the network down to ask whether this origin holds
            // a synced catalogue, and an uncached probe would answer "no" for a
            // device that is full of music.
            .then((cache) => cache.addAll([OFFLINE_URL, PROBE_URL]))
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
        // The probe answers from cache first. An iframe load is a navigation,
        // so without this it would fall through to the offline page below and
        // the shell would never get its answer — and it is framed precisely
        // when the network is down, so trying the network first only adds a
        // timeout to a question that has to be quick.
        if (url.pathname === PROBE_URL) {
            event.respondWith(
                caches.match(PROBE_URL).then((hit) => hit ?? fetch(request)),
            );

            return;
        }

        const isDownloadsPage = url.pathname === '/app/downloads';

        event.respondWith(
            fetchWithRetry(request)
                .then((response) => {
                    if (isDownloadsPage && response.ok) {
                        const copy = response.clone();
                        caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => (isDownloadsPage
                    ? caches.match(request).then((hit) => hit ?? offlinePageFor(url))
                    : offlinePageFor(url))),
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
 * One retry before giving a navigation up.
 *
 * A phone loses a request to a passing lift or a moment of bad wifi, and a
 * failed navigation falls back to the offline page — which renders as the home
 * screen. Tapping through the app on imperfect signal would flash white and
 * throw the user back to the start, for a request that would have succeeded on
 * a second attempt.
 *
 * Only one retry, and only for navigations: a genuinely offline device should
 * reach its downloaded music quickly rather than sitting through a series of
 * timeouts.
 */
/**
 * The offline page, told which page it is standing in for.
 *
 * The browser's address bar becomes /offline.html, so the shell reading
 * location.pathname had no idea what was asked for and rebuilt the home screen
 * every time. Tapping into an album while offline landed on the library, which
 * reads as the app losing its place.
 *
 * Passed as a query parameter because a redirect would change the URL again and
 * lose it a second time.
 */
async function offlinePageFor(url) {
    // Recorded so a device can say what happened. A navigation that falls back
    // here is invisible otherwise — the user sees a white flash and the home
    // screen, and there is nothing anywhere saying which page was lost or why.
    report('offline-fallback', { wanted: url.pathname + url.search });

    const response = await caches.match(OFFLINE_URL);

    if (!response) return response;

    const body = await response.text();
    const wanted = url.pathname + url.search;

    return new Response(
        body.replace('</head>', `<script>window.__soundchexWanted=${JSON.stringify(wanted)};</script></head>`),
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
}

async function fetchWithRetry(request) {
    try {
        return await fetch(request);
    } catch (error) {
        report('navigation-retry', {
            url: new URL(request.url).pathname,
            reason: String(error?.message ?? error).slice(0, 120),
        });

        // A navigation body can only be read once, so the retry needs its own
        // copy of the request.
        try {
            return await fetch(request.clone());
        } catch {
            throw error;
        }
    }
}

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
