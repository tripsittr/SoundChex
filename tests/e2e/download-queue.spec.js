import { expect, test } from '@playwright/test';
import { appReady, signIn } from './helpers.js';

/**
 * Downloads run one at a time.
 *
 * Each button used to start its own transfer, so tapping five rows opened five
 * concurrent connections to the same server. On a phone that is slower than
 * doing them in turn — they compete for the same bandwidth, none finishes
 * early — and it makes a dropped connection take down five downloads instead
 * of one.
 */
test.describe('download queue', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true });
        await page.evaluate(() => window.soundchexDownloadQueue.clear());
    });

    test('only one download runs at a time', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const queue = window.soundchexDownloadQueue;
            const order = [];
            let concurrent = 0;
            let peak = 0;

            const run = async (item) => {
                concurrent += 1;
                peak = Math.max(peak, concurrent);
                order.push(`start:${item.id}`);
                await new Promise((resolve) => setTimeout(resolve, 50));
                order.push(`end:${item.id}`);
                concurrent -= 1;
            };

            const entries = [1, 2, 3].map((id) => queue.enqueue({ id, url: '/x', title: `T${id}` }, run));

            await Promise.all(entries.map((entry) => entry.promise));

            return { peak, order };
        });

        expect(result.peak, 'never more than one in flight').toBe(1);
        expect(result.order).toEqual([
            'start:1', 'end:1', 'start:2', 'end:2', 'start:3', 'end:3',
        ]);
    });

    test('the same track queued twice is downloaded once', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const queue = window.soundchexDownloadQueue;
            let runs = 0;

            const run = async () => {
                runs += 1;
                await new Promise((resolve) => setTimeout(resolve, 40));
            };

            const first = queue.enqueue({ id: 9, url: '/x', title: 'A' }, run);
            const second = queue.enqueue({ id: 9, url: '/x', title: 'A' }, run);

            await Promise.all([first.promise, second.promise]);

            return { runs, first: first.queued, second: second.queued };
        });

        expect(result.first).toBe(true);
        expect(result.second, 'the duplicate is refused').toBe(false);
        expect(result.runs, 'downloaded once').toBe(1);
    });

    test('position counts the download already running', async ({ page }) => {
        // waiting.length alone reports 1 for an item queued behind a running
        // download, because that one has been shifted out of the list — so
        // nothing ever looked queued and the toast never appeared.
        const position = await page.evaluate(async () => {
            const queue = window.soundchexDownloadQueue;

            queue.enqueue({ id: 1, url: '/x', title: 'Blocker' }, () => new Promise((r) => setTimeout(r, 400)));

            await new Promise((resolve) => setTimeout(resolve, 60));

            return queue.enqueue({ id: 2, url: '/x', title: 'Behind' }, () => Promise.resolve()).position;
        });

        expect(position).toBe(2);
    });

    test('a queued tap says so, and still downloads', async ({ page }) => {
        await page.evaluate(() => {
            window.soundchexDownloadQueue.enqueue(
                { id: 999, url: '/slow', title: 'Blocker' },
                () => new Promise((resolve) => setTimeout(resolve, 2500)),
            );
        });

        const button = page.locator('[data-download]:not(#download-toggle)').first();

        await button.click();

        const toast = page.locator('#soundchex-toast');

        await expect(toast).toHaveAttribute('data-visible', 'true');
        await expect(toast).toContainText(/download queue/i);

        // The queue delays it; it must not swallow it.
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });
    });
});

/**
 * Downloading more than one track at a time.
 *
 * Album, playlist and the track menu all rendered a [data-download-batch]
 * button carrying its tracks, and nothing was bound to any of them —
 * setupBatchDownload() binds a single element by id, so every one was inert.
 *
 * "Download all" is separate again: the songs list is paginated, so a button
 * built from what is on screen would quietly take the first page and call it
 * the library.
 */
test.describe('downloading in bulk', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true });
        await page.evaluate(() => window.soundchexDownloadQueue.clear());
    });

    test('the library endpoint returns every track, not one page', async ({ page }) => {
        const [payload, onScreen] = await Promise.all([
            page.evaluate(() => fetch('/app/downloadable', { headers: { Accept: 'application/json' } })
                .then((r) => r.json())),
            page.locator('li[data-long-press-menu]').count(),
        ]);

        expect(payload.tracks.length).toBeGreaterThanOrEqual(onScreen);

        for (const track of payload.tracks) {
            // The client needs all four: an id to store under, a url to fetch,
            // a title to report, and a size to total before starting.
            expect(track).toHaveProperty('id');
            expect(track).toHaveProperty('url');
            expect(track).toHaveProperty('title');
            expect(track).toHaveProperty('size');
        }
    });

    test('download all stores the library', async ({ page }) => {
        const button = page.locator('[data-download-library]').first();

        await expect(button).toBeVisible();
        await button.click();

        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 30000 });

        const [stored, expected] = await Promise.all([
            page.evaluate(() => window.soundchexDownloads.list().then((l) => l.length)),
            page.evaluate(() => fetch('/app/downloadable', { headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then((p) => p.tracks.length)),
        ]);

        expect(stored).toBe(expected);
    });

    test('pressing it again does not download everything twice', async ({ page }) => {
        const button = page.locator('[data-download-library]').first();

        await button.click();
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 30000 });

        const before = await page.evaluate(() => window.soundchexDownloads.list().then((l) => l.length));

        await button.click();
        await page.waitForTimeout(1500);

        // Already-stored tracks are skipped rather than fetched again, which is
        // what makes this safe to press twice.
        const after = await page.evaluate(() => window.soundchexDownloads.list().then((l) => l.length));

        expect(after).toBe(before);
    });

    test('an album download button is bound', async ({ page }) => {
        await page.goto('/app/albums');
        await page.waitForTimeout(1200);

        // An album's own page, not the index: both match /app/album, and the
        // index has no tracks on it.
        const album = page.locator('a[href*="/app/album?artist="]').first();

        if (await album.count() === 0) test.skip();

        await album.click();
        await page.waitForURL(/\/app\/album\?/, { timeout: 15000 });
        await page.waitForTimeout(1200);

        const button = page.locator('[data-download-batch]').first();

        await expect(button).toBeVisible();
        await button.click();

        // Inert before this change: it did nothing and said nothing.
        await expect(button).toHaveAttribute('data-state', /downloading|stored/, { timeout: 30000 });
    });
});
