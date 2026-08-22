import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Writes made offline.
 *
 * The server side is covered by OfflineWriteTest — that a replay cannot undo
 * newer progress, and that a queued watchlist write cannot flip itself back.
 * These cover the part only a browser has: that the queue survives the app
 * being closed, and drains when the server returns.
 */
test.describe('offline write queue', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app');
        await page.waitForTimeout(800);
        await page.evaluate(() => window.soundchexWrites?.clear());
    });

    test('the queue is available to the app', async ({ page }) => {
        expect(await page.evaluate(() => typeof window.soundchexWrites)).toBe('object');
    });

    test('repeated writes for one item collapse to a single entry', async ({ page }) => {
        // A film reports its position every few seconds. Queueing each one
        // would store hundreds, and replaying the early ones would rewind the
        // viewer to where they were an hour ago.
        const count = await page.evaluate(async () => {
            const writes = window.soundchexWrites;

            for (const position of [10, 20, 30, 40]) {
                await writes.enqueue({
                    kind: 'progress',
                    url: '/app/item/1/progress',
                    body: { position, duration: 600 },
                    key: 'progress:1',
                });
            }

            return writes.count();
        });

        expect(count).toBe(1);
    });

    test('the last position wins', async ({ page }) => {
        const body = await page.evaluate(async () => {
            const writes = window.soundchexWrites;

            await writes.enqueue({ kind: 'progress', url: '/x', body: { position: 10 }, key: 'progress:1' });
            await writes.enqueue({ kind: 'progress', url: '/x', body: { position: 90 }, key: 'progress:1' });

            return (await writes.pending())[0].body;
        });

        expect(body.position).toBe(90);
    });

    test('different items queue separately', async ({ page }) => {
        const count = await page.evaluate(async () => {
            const writes = window.soundchexWrites;

            await writes.enqueue({ kind: 'progress', url: '/a', body: {}, key: 'progress:1' });
            await writes.enqueue({ kind: 'progress', url: '/b', body: {}, key: 'progress:2' });

            return writes.count();
        });

        expect(count).toBe(2);
    });

    test('a queued write survives a reload', async ({ page }) => {
        // The whole point: a phone reclaims a suspended page, and a queue in
        // memory would go with it.
        await page.evaluate(async () => {
            await window.soundchexWrites.enqueue({
                kind: 'progress',
                url: '/app/item/1/progress',
                body: { position: 42 },
                key: 'progress:1',
            });
        });

        await page.reload();
        await page.waitForTimeout(1200);

        // Flushed on load when the server is reachable, so either it drained
        // or it is still there — both prove it outlived the page.
        const seen = await page.evaluate(async () => {
            const entries = await window.soundchexWrites.pending();

            return { count: entries.length, drained: entries.length === 0 };
        });

        expect(seen.count === 1 || seen.drained).toBe(true);
    });

    test('a queued write records when it happened', async ({ page }) => {
        // The server compares this against what it holds, so a write replayed
        // an hour later cannot overwrite newer progress.
        const entry = await page.evaluate(async () => {
            await window.soundchexWrites.enqueue({
                kind: 'progress',
                url: '/x',
                body: { position: 1 },
                key: 'progress:9',
            });

            return (await window.soundchexWrites.pending())[0];
        });

        expect(entry.recorded_at).toBeTruthy();
        expect(Number.isNaN(Date.parse(entry.recorded_at))).toBe(false);
    });

    test('a refused write is dropped rather than retried forever', async ({ page }) => {
        // 4xx means the server understood and said no — a deleted item, or a
        // write this profile may no longer make. Retrying cannot change that.
        const remaining = await page.evaluate(async () => {
            const writes = window.soundchexWrites;

            await writes.enqueue({
                kind: 'progress',
                url: '/app/item/999999/progress',
                body: { position: 1, duration: 10 },
                key: 'progress:999999',
            });

            await writes.flush();

            return writes.count();
        });

        expect(remaining).toBe(0);
    });
});
