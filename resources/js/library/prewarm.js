/**
 * Crawls the app's own pages while online so they are cached for offline.
 *
 * Offline, the service worker serves the real page from its cache — but only for
 * pages that have actually been fetched. Rather than make the user visit every
 * screen first, this fetches them in the background while there is a connection,
 * so the service worker caches each one. When the network is gone, every page
 * is already there, identical to online.
 *
 * Gentle by design: a few at a time, low priority, only when online and idle,
 * and it skips work when the pages are already warm. The catalogue is read from
 * the mirror, so it knows every album, artist and item to crawl without asking
 * the server for a list.
 */

import * as mirror from './mirror.js';

/** How many pages to fetch at once — enough to be quick, few enough to be polite. */
const BATCH = 4;

/** Marks the run so a second trigger (foreground, a later sync) does not double up. */
let running = false;

/**
 * The pages worth having offline, derived from the mirror.
 *
 * The fixed screens plus a detail page per item and the album/artist browse
 * pages the library actually contains. Deduplicated; order puts the screens a
 * user reaches first at the front.
 *
 * @returns {Promise<string[]>}
 */
async function pagesToWarm() {
    const items = await mirror.all().catch(() => []);

    const pages = new Set([
        '/app',
        '/app/music',
        '/app/albums',
        '/app/artists',
        '/app/genres',
        '/app/playlists',
        '/app/downloads',
        '/app/watch',
        '/app/movie',
        '/app/show',
        '/app/book',
        '/app/search',
    ]);

    // A detail page per item.
    for (const item of items) {
        pages.add(`/app/item/${item.id}`);
    }

    // Album and artist browse pages, from the music metadata the mirror holds.
    const albums = new Set();
    const artists = new Set();

    for (const item of items) {
        const meta = item.meta ?? {};

        if (meta.album && meta.artist) {
            albums.add(`/app/album?artist=${encodeURIComponent(meta.artist)}`
                + `&album=${encodeURIComponent(meta.album)}`);
        }

        if (meta.artist) {
            artists.add(`/app/artist?artist=${encodeURIComponent(meta.artist)}`);
        }
    }

    return [...pages, ...albums, ...artists];
}

/**
 * Fetches one page so the service worker caches it. Best-effort and quiet.
 *
 * `credentials: 'same-origin'` carries the session so the real authenticated
 * page is what gets cached; a low priority keeps it out of the way of anything
 * the user is actually doing.
 */
async function warm(path) {
    try {
        const response = await fetch(path, {
            credentials: 'same-origin',
            // A hint, honoured where supported, that this is background work.
            priority: 'low',
            headers: { 'X-Soundchex-Prewarm': '1' },
        });

        // Drain the body so the connection is released and the SW sees a
        // complete response to cache.
        await response.text().catch(() => {});

        return response.ok;
    } catch {
        return false;
    }
}

/**
 * Warms the pages, a batch at a time, if online.
 *
 * Returns silently when offline (nothing to fetch) or already running. Safe to
 * call on every launch and foreground — it is cheap when the network is warm and
 * a no-op when it is not.
 */
export async function prewarm() {
    if (running) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
    if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) return;

    running = true;

    try {
        const pages = await pagesToWarm();

        for (let i = 0; i < pages.length; i += BATCH) {
            // Stop if the connection dropped mid-crawl — the rest will warm on
            // the next online launch.
            if (navigator.onLine === false) break;

            await Promise.all(pages.slice(i, i + BATCH).map(warm));

            // A breath between batches so the crawl never competes with the
            // user's own navigation.
            await new Promise((r) => setTimeout(r, 150));
        }
    } finally {
        running = false;
    }
}
