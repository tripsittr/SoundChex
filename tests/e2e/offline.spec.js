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
