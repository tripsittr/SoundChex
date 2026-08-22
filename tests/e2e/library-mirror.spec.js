import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * The device's copy of the catalogue.
 *
 * Vitest covers the query logic as pure functions. These cover what only a
 * browser has: a real IndexedDB, a real upgrade path, and the delta that has
 * to *remove* rows — the case a merge-only sync silently gets wrong, leaving a
 * newly blocked film playable on a child's device.
 */
test.describe('library mirror', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app');
        await page.waitForTimeout(800);
    });

    test('it is available to the app', async ({ page }) => {
        expect(await page.evaluate(() => typeof window.soundchexLibrary)).toBe('object');
    });

    test('a full sync stores items and the sync marker', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const lib = window.soundchexLibrary;

            await lib.mirror.replaceAll(
                [
                    { id: 1, type: 'music', title: 'One', parent_id: null, playable: true, meta: {} },
                    { id: 2, type: 'movie', title: 'Two', parent_id: null, playable: true, meta: {} },
                ],
                { syncedAt: '2026-08-14T00:00:00+00:00', etag: 'W/"abc"' },
            );

            return {
                count: await lib.mirror.count(),
                syncedAt: await lib.mirror.syncedAt(),
                etag: await lib.mirror.storedEtag(),
            };
        });

        expect(result.count).toBe(2);
        expect(result.syncedAt).toBe('2026-08-14T00:00:00+00:00');
        expect(result.etag).toBe('W/"abc"');
    });

    test('a full sync drops what the server no longer sends', async ({ page }) => {
        // Merging instead of replacing would leave a deleted — or newly
        // blocked — item on the device forever.
        const remaining = await page.evaluate(async () => {
            const lib = window.soundchexLibrary;

            await lib.mirror.replaceAll([{ id: 1, type: 'music', title: 'Gone', parent_id: null, meta: {} }]);
            await lib.mirror.replaceAll([{ id: 2, type: 'music', title: 'Kept', parent_id: null, meta: {} }]);

            return (await lib.all()).map((item) => item.id);
        });

        expect(remaining).toEqual([2]);
    });

    test('a delta adds, updates and removes', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const lib = window.soundchexLibrary;

            await lib.mirror.replaceAll([
                { id: 1, type: 'music', title: 'Original', parent_id: null, meta: {} },
                { id: 2, type: 'movie', title: 'Blocked Later', parent_id: null, meta: {} },
            ]);

            await lib.mirror.applyDelta(
                [
                    { id: 1, type: 'music', title: 'Renamed', parent_id: null, meta: {} },
                    { id: 3, type: 'book', title: 'New', parent_id: null, meta: {} },
                ],
                [2],
            );

            return {
                titles: (await lib.all()).map((item) => item.title).sort(),
                blockedGone: (await lib.mirror.find(2)) === undefined,
            };
        });

        expect(result.titles).toEqual(['New', 'Renamed']);
        expect(result.blockedGone).toBe(true);
    });

    test('clearing leaves nothing behind', async ({ page }) => {
        // The mirror holds exactly what one profile may see, so a profile
        // switch must not inherit the previous one's rows.
        const after = await page.evaluate(async () => {
            const lib = window.soundchexLibrary;

            await lib.mirror.replaceAll([{ id: 1, type: 'music', title: 'One', parent_id: null, meta: {} }]);
            await lib.mirror.clear();

            return { count: await lib.mirror.count(), syncedAt: await lib.mirror.syncedAt() };
        });

        expect(after.count).toBe(0);
        expect(after.syncedAt).toBeUndefined();
    });

    test('queries run against the stored copy', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const lib = window.soundchexLibrary;

            await lib.mirror.replaceAll([
                { id: 1, type: 'music', title: 'Chicago', parent_id: null, playable: true, meta: { artist: 'flipturn', album: 'Heavy Colors' } },
                { id: 2, type: 'music', title: 'Alpha', parent_id: null, playable: true, meta: { artist: 'flipturn', album: 'Heavy Colors' } },
                { id: 3, type: 'movie', title: 'Backrooms', parent_id: null, playable: true, meta: {} },
            ]);

            const all = await lib.all();

            return {
                music: lib.byType(all, 'music').length,
                albums: lib.albums(all).length,
                found: lib.search(all, 'chicago').length,
                artists: lib.artists(all).length,
            };
        });

        expect(result).toMatchObject({ music: 2, albums: 1, found: 1, artists: 1 });
    });
});
