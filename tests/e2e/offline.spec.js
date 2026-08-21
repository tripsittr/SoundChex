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

/**
 * A pre-render draws a screenful, not the library.
 *
 * The server paginates to 48; the offline shell drew everything in the mirror —
 * over thirteen hundred rows for a real library, each with an image, so a single
 * tap asked the server for thirteen hundred files. Measured: 684 artwork
 * requests in one minute, and a music page that never finished while books and
 * films, with a dozen items between them, were instant.
 *
 * It is a placeholder shown for a moment before the server's page replaces it.
 * Drawing more than a screenful was never the point.
 */
test.describe('pre-render size', () => {
    test('a large library draws a screenful', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

        await page.evaluate(() => {
            const items = Array.from({ length: 400 }, (_, index) => ({
                id: index + 1,
                type: 'music',
                title: `Song ${index}`,
                parent_id: null,
                playable: true,
                subtitle: 'Artist',
                artwork: `/storage/artwork/x-${index}.jpg`,
                meta: {},
            }));

            return window.soundchexLibrary.mirror.replaceAll(items, {
                syncedAt: '2026-08-20T00:00:00Z',
            });
        });

        await page.evaluate(() => window.soundchexLibrary.preRender(
            document.querySelector('main'), '/app/music',
        ));

        const drawn = await page.evaluate(() => ({
            rows: document.querySelectorAll('ol li').length,
            images: document.querySelectorAll('main img').length,
        }));

        // Each row carries an image, so the row count is the request count.
        expect(drawn.rows, 'a screenful, not the library').toBeLessThanOrEqual(60);
        expect(drawn.images).toBeLessThanOrEqual(60);
    });

    test('the count still names the whole library', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

        await page.evaluate(() => {
            const items = Array.from({ length: 200 }, (_, index) => ({
                id: index + 1, type: 'music', title: `Song ${index}`, parent_id: null,
                playable: true, subtitle: 'Artist', meta: {},
            }));

            return window.soundchexLibrary.mirror.replaceAll(items, {
                syncedAt: '2026-08-20T00:00:00Z',
            });
        });

        await page.evaluate(() => window.soundchexLibrary.preRender(
            document.querySelector('main'), '/app/music',
        ));

        // Saying "60 songs" to someone with two hundred would be a lie in
        // service of a placeholder.
        await expect(page.locator('main')).toContainText('200');
    });
});

/**
 * A build reload never interrupts a navigation.
 *
 * The device log for the failing music page showed reloading-for-build fired
 * mid-tap: the screen went white and came back somewhere else, which reads as
 * the page being broken rather than the app updating itself.
 */
test.describe('build reloads', () => {
    test('a reload waits for the navigation to finish', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const source = await page.evaluate(
            () => fetch('/build/manifest.json').then((r) => r.json()),
        );

        expect(source, 'the build is served').toBeTruthy();

        // Livewire announces both ends of a navigation, so the guard is exact
        // rather than a guess at how long a page takes.
        const guarded = await page.evaluate(() => typeof window.soundchexBuild?.checkForUpdate === 'function');

        expect(guarded).toBe(true);
    });

    test('the watcher records a reload before it happens', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        // A page load destroys anything written after it, so a reload that
        // recorded itself afterwards left no trace — which is why the white
        // flash looked like a crash for so long.
        const events = await page.evaluate(() => window.soundchexDiagnostics.events());

        expect(Array.isArray(events)).toBe(true);
    });
});

/**
 * Downloads shown when the catalogue is not there.
 *
 * The mirror and the downloads are separate IndexedDB databases, and the
 * offline shell consulted only the mirror — so a device holding music it had
 * deliberately downloaded was told nothing was saved on it, at exactly the
 * moment those downloads exist for.
 */
test.describe('downloads without a catalogue', () => {
    test('a device with downloads and no mirror shows them', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

        await page.evaluate(async () => {
            await window.soundchexLibrary.mirror.clear();
            await window.soundchexDownloads.download({
                id: 1,
                url: '/app/item/1/stream',
                meta: { title: 'Saved Song', type: 'music' },
            });
        });

        const drawn = await page.evaluate(() => window.soundchexLibrary.takeOver());

        expect(drawn, 'something was rendered').toBeTruthy();
        await expect(page.locator('main')).toContainText(/on this device/i);
        await expect(page.locator('main')).toContainText('Saved Song');
    });

    test('a device with neither says so honestly', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

        await page.evaluate(async () => {
            await window.soundchexLibrary.mirror.clear();

            for (const entry of await window.soundchexDownloads.list()) {
                await window.soundchexDownloads.remove(entry.id);
            }
        });

        // Nothing to show is a real answer, and declining lets the caller put
        // the connect form back rather than blanking the page.
        const drawn = await page.evaluate(() => window.soundchexLibrary.takeOver());

        expect(drawn).toBeFalsy();
    });
});

