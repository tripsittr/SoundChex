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
const VERSION = '8dd19ad05cee';
const ASSET_CACHE = `soundchex-assets-${VERSION}`;

// The real /app pages, cached as they are visited or crawled so offline shows
// the actual page. NOT versioned by build — a page's usefulness offline outlives
// a frontend deploy, and network-first means online never serves a stale one.
// Pruned by count (prunePageCache) and cleared on sign-out.
const PAGE_CACHE = 'soundchex-pages';
const PAGE_CACHE_MAX = 400;
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

    // Sign-out clears the cached pages: they are one account's authenticated
    // views, and the next person to use this device must not reach them offline.
    if (event.data?.tell === 'soundchex:signed-out') {
        event.waitUntil?.(caches.delete(PAGE_CACHE));
        caches.delete(PAGE_CACHE);
    }
});
const PROBE_URL = '/offline-probe.html';

/**
 * Keeps the page cache bounded to the most-recent PAGE_CACHE_MAX entries.
 *
 * The Cache API preserves insertion order, so the first keys are the oldest.
 * Pages are tens of KB, so even 400 is small, but an unbounded cache of every
 * page ever seen would still creep.
 */
async function prunePageCache(cache) {
    const keys = await cache.keys();

    if (keys.length <= PAGE_CACHE_MAX) return;

    for (const req of keys.slice(0, keys.length - PAGE_CACHE_MAX)) {
        await cache.delete(req);
    }
}

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
                    // The page cache is kept across builds — its pages are still
                    // the right pages to show offline after a deploy, and
                    // network-first refreshes each as it is visited.
                    .filter((key) => key.startsWith('soundchex-')
                        && key !== ASSET_CACHE
                        && key !== PAGE_CACHE)
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

        // Every /app page is cached as it is fetched and served from that cache
        // offline — so offline shows the *real* page, the same Blade HTML with
        // the same styling and scripts, identical to online. Network-first keeps
        // it honest: online always gets the fresh page, so a stale copy only
        // appears offline, where a slightly-old real page is exactly what
        // "identical offline" means. The cache is this device's own
        // authenticated view (per-origin, per-device), so nothing leaks.
        //
        // A background crawler (library/prewarm.js) fetches the main pages while
        // online so they are cached before the network is ever gone — the user
        // does not have to have visited a page for it to work offline.
        const isAppPage = url.pathname === '/app' || url.pathname.startsWith('/app/');

        // Match by pathname, ignoring the query. The native shell opens /app as
        // /app?shell=…, so a cached /app and a navigated /app?shell=… would not
        // match and offline fell straight through. Normalise the key on write,
        // ignoreSearch on read.
        const pageKey = new Request(url.origin + url.pathname, { headers: request.headers });

        event.respondWith(
            fetchWithRetry(request)
                .then((response) => {
                    if (isAppPage && response.ok && response.type === 'basic') {
                        const copy = response.clone();

                        caches.open(PAGE_CACHE).then(async (cache) => {
                            await cache.put(pageKey, copy);
                            await prunePageCache(cache);
                        });
                    }

                    return response;
                })
                .catch(() => (isAppPage
                    ? caches.match(pageKey, { ignoreSearch: true }).then((hit) => hit ?? offlinePageFor(url))
                    : offlinePageFor(url))),
        );

        return;
    }

    // Artwork is answered from cache alone when it is there.
    //
    // stale-while-revalidate still fetches every image in the background to
    // refresh it, so a music page showing forty covers made forty requests
    // whether or not they were cached — 6,958 in one afternoon here, peaking at
    // 1,146 in a minute. The filename carries the media item's id, so a
    // different image is a different URL and there is nothing to refresh.
    if (url.pathname.startsWith('/storage/artwork/')) {
        event.respondWith(
            caches.match(request).then((hit) => hit ?? staleWhileRevalidate(request)),
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
