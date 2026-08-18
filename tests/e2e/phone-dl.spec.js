import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Downloading on WebKit.
 *
 * Downloads worked in Chrome and never on the phone, because Safari aborts an
 * IndexedDB transaction that stores a Blob — and does it with a *null* error,
 * so the failure carried no message and the bytes just written were rolled
 * back. Records are `{ buffer, type }` now.
 *
 * This has to run on WebKit: the desktop project stores a Blob happily and
 * would have passed against the broken code, which is exactly how the bug
 * survived four rounds of offline work.
 */
test.describe('downloads on a phone', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1200);
    });

    test('a track downloads and is playable from storage', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;

            await downloads.download({
                id: 1,
                url: '/app/item/1/stream',
                meta: { title: 'Test Tone One', type: 'music' },
            });

            return {
                stored: (await downloads.list()).length,
                playable: !!(await downloads.localUrl(1)),
                reported: await downloads.isDownloaded(1),
            };
        });

        expect(result.stored).toBe(1);
        expect(result.playable).toBe(true);
        expect(result.reported).toBe(true);
    });

    test('what was downloaded survives a reload', async ({ page }) => {
        // The whole point: storage that outlives the page.
        await page.evaluate(() => window.soundchexDownloads.download({
            id: 1,
            url: '/app/item/1/stream',
            meta: { title: 'Test Tone One', type: 'music' },
        }));

        await page.reload();
        await page.waitForTimeout(1200);

        expect(await page.evaluate(() => window.soundchexDownloads.isDownloaded(1))).toBe(true);
    });

    test('removing a download frees it', async ({ page }) => {
        const after = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;

            await downloads.download({ id: 1, url: '/app/item/1/stream', meta: { title: 'T', type: 'music' } });
            await downloads.remove(1);

            return downloads.isDownloaded(1);
        });

        expect(after).toBe(false);
    });
});
