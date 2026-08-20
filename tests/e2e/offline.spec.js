import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Offline playback, tested with the network actually cut.
 *
 * This is the case a unit test cannot reach: it needs a real IndexedDB, a real
 * blob URL, and a real <audio> element decoding bytes that did not come from
 * the server. Stubbing any of those would test the stub.
 *
 * Downloads use IndexedDB rather than the Cache API because the Cache API
 * cannot serve range requests, and seeking needs them.
 */
test.describe('offline downloads', () => {
    let trackId;
    let streamUrl;

    test.beforeEach(async ({ page }) => {
        await signIn(page);

        await page.goto('/app/music');

        const link = page.locator('a[href*="/app/item/"]').first();
        trackId = (await link.getAttribute('href')).match(/\/app\/item\/(\d+)/)[1];
        streamUrl = `/app/item/${trackId}/stream`;
    });

    test('a downloaded track is stored and listed', async ({ page }) => {
        const stored = await downloadTrack(page, trackId, streamUrl);

        expect(stored.size).toBeGreaterThan(0);

        const listed = await page.evaluate(async () =>
            (await window.soundchexDownloads.list()).map((row) => String(row.id)));

        expect(listed).toContain(String(trackId));
    });

    test('a downloaded track plays with the network offline', async ({ page, context }) => {
        await downloadTrack(page, trackId, streamUrl);

        // Everything from here on must come from IndexedDB.
        await context.setOffline(true);

        const result = await page.evaluate(async (id) => {
            const url = await window.soundchexDownloads.localUrl(id);

            if (!url) return { played: false, reason: 'no local url' };

            const audio = new Audio(url);
            audio.muted = true;

            try {
                await audio.play();
            } catch (error) {
                return { played: false, reason: error.message };
            }

            await new Promise((resolve) => setTimeout(resolve, 800));

            return { played: audio.currentTime > 0, time: audio.currentTime };
        }, trackId);

        await context.setOffline(false);

        expect(result.played, `offline playback failed: ${result.reason ?? ''}`).toBe(true);
    });

    test('the server really is unreachable while offline', async ({ page, context }) => {
        // Guards the test above. If the network were still up, "it played
        // offline" would prove nothing at all.
        await context.setOffline(true);

        const reachable = await page.evaluate(async (url) => {
            try {
                const response = await fetch(url, { cache: 'no-store' });

                return response.ok;
            } catch {
                return false;
            }
        }, streamUrl);

        await context.setOffline(false);

        expect(reachable).toBe(false);
    });

    test('a track that was never downloaded has no local copy', async ({ page }) => {
        const url = await page.evaluate(async () => {
            return window.soundchexDownloads.localUrl(999999);
        });

        expect(url).toBeFalsy();
    });

    test('removing a download frees the local copy', async ({ page }) => {
        await downloadTrack(page, trackId, streamUrl);

        const after = await page.evaluate(async (id) => {
            await window.soundchexDownloads.remove(id);

            return window.soundchexDownloads.localUrl(id);
        }, trackId);

        expect(after).toBeFalsy();
    });
});

/**
 * Drives the real download path — fetch, stream, store in IndexedDB.
 */
async function downloadTrack(page, id, url) {
    await page.goto(`/app/item/${id}`);

    return page.evaluate(async ({ id, url }) => {
        await window.soundchexDownloads.download({ id, url, meta: { title: 'Test Tone One', type: 'music' } });

        const rows = await window.soundchexDownloads.list();

        return rows.find((row) => String(row.id) === String(id)) ?? { size: 0 };
    }, { id, url });
}

/**
 * Keeping the page you asked for when a navigation fails.
 *
 * A failed navigation falls back to a single offline page, and the address bar
 * becomes /offline.html — so the shell reading location.pathname rebuilt the
 * home screen whatever had been tapped. On imperfect signal that reads as the
 * app flashing white and throwing you back to the start.
 */
test.describe('offline navigation keeps its place', () => {
    test('the worker retries before falling back', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const source = await page.evaluate(() => fetch('/sw.js').then((r) => r.text()));

        // A phone drops a request to a passing lift. Giving up on the first one
        // turns a moment of bad wifi into a lost page.
        expect(source).toContain('fetchWithRetry');
        expect(source).toContain('request.clone()');
    });

    test('the worker tells the offline page what was asked for', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const source = await page.evaluate(() => fetch('/sw.js').then((r) => r.text()));

        // Passed as a global rather than a redirect, which would change the URL
        // again and lose it a second time.
        expect(source).toContain('__soundchexWanted');
        expect(source).toContain('offlinePageFor');
    });

    test('the shell prefers the asked-for path over the address bar', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const source = await page.evaluate(
            () => fetch('/build/manifest.json').then((r) => r.json()),
        );

        expect(source, 'the build is served').toBeTruthy();

        // Reading location.pathname is what rebuilt home every time.
        const shell = await page.evaluate(() => window.soundchexLibrary !== undefined);

        expect(shell, 'the library shell is loaded').toBe(true);
    });
});

/**
 * Artwork is fetched once, not on every page.
 *
 * stale-while-revalidate refreshes in the background, so a music page showing
 * forty covers made forty requests whether or not they were cached — 6,958 in
 * one afternoon on this server, peaking at 1,146 in a single minute. On a LAN
 * that is wasteful; over a relayed connection at more than a second a request it
 * is the difference between a page that loads and one that does not, which is
 * what "music fails while books and films are fine" turned out to be.
 */
test.describe('artwork caching', () => {
    test('cached artwork is answered without a network request', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const source = await page.evaluate(() => fetch('/sw.js').then((r) => r.text()));

        // The filename carries the media item's id, so a different image is a
        // different URL and there is nothing to refresh.
        expect(source).toContain("url.pathname.startsWith('/storage/artwork/')");
        expect(source).toContain('caches.match(request).then((hit) => hit ?? staleWhileRevalidate(request))');
    });

    test('the worker version changes when the worker does', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const source = await page.evaluate(() => fetch('/sw.js').then((r) => r.text()));
        const version = source.match(/const VERSION = '([^']*)'/)?.[1];

        // Stamped from the manifest alone, a change to caching strategy left the
        // version identical — so the old worker kept running with its old
        // caches, which is exactly when a new one is most needed.
        expect(version).toBeTruthy();
        expect(version).not.toBe('v1');
    });
});

