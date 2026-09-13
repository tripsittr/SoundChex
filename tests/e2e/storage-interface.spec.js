import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * The one storage interface (Step 1 of the offline rebuild).
 *
 * Everything offline is meant to talk to this and never learn which backend is
 * live. This proves the seam exists, chooses a backend by capability, and
 * round-trips a file through the same store the download path writes to — so a
 * later native backend has a contract to match and a test that will catch it
 * diverging.
 *
 * Runs on both engines. On Chromium and on WebKit the backend is `indexeddb`
 * today; when the native backend lands (Step 2) this is where the device
 * picking it up gets asserted.
 */
test.describe('the offline storage interface', () => {
    test('is reachable and chooses a backend', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexStorage !== undefined, null, { timeout: 20000 });

        const backend = await page.evaluate(() => window.soundchexStorage.backend());
        // backend() resolves the async probe; in a browser there is no native
        // bridge, so it settles on indexeddb.

        // In a browser (and in Tauri until Step 2) it is IndexedDB.
        expect(backend).toBe('indexeddb');
    });

    test('round-trips a file: save, open, has, list, remove', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexStorage !== undefined, null, { timeout: 20000 });

        const result = await page.evaluate(async () => {
            const s = window.soundchexStorage;
            const id = 'iface-probe-1';

            const blob = new Blob([new Uint8Array([1, 2, 3, 4, 5])], { type: 'audio/mpeg' });

            await s.save(id, blob, { type: 'audio/mpeg', title: 'Probe' });

            const has = await s.has(id);
            const url = await s.open(id);
            const list = await s.list();
            const listed = list.some((row) => String(row.id) === id);

            await s.remove(id);
            const afterRemove = await s.has(id);

            if (url) URL.revokeObjectURL(url);

            return { has, hasUrl: typeof url === 'string' && url.length > 0, listed, afterRemove };
        });

        expect(result.has, 'a saved file reports as present').toBe(true);
        expect(result.hasUrl, 'open() returns a usable URL').toBe(true);
        expect(result.listed, 'a saved file appears in list()').toBe(true);
        expect(result.afterRemove, 'remove() deletes it').toBe(false);
    });

    test('space() reports whether the number is real', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexStorage !== undefined, null, { timeout: 20000 });

        const space = await page.evaluate(() => window.soundchexStorage.space());

        // The IndexedDB backend can only offer the browser quota, and says so
        // through `source`. What matters is that the shape is honest: a
        // `known` flag and a source, so a caller is never handed a confident
        // wrong number (the failure the native backend's statvfs fixes).
        expect(space).toHaveProperty('known');
        expect(space).toHaveProperty('source');
        expect(space.source).toBe('quota');
    });
});
