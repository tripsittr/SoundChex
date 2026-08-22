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

/**
 * Seeing what is downloading.
 *
 * "Added to download queue" and then no sign of anything is indistinguishable
 * from a button that did nothing — especially for a whole library, where the
 * first file takes a while and the toast has long gone.
 */
test.describe('the download queue in the menu', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true });
        await page.evaluate(() => window.soundchexDownloadQueue.clear());
    });

    test('it is hidden when nothing is downloading', async ({ page }) => {
        // A permanent empty row is clutter.
        await expect(page.locator('[data-download-queue]')).toBeHidden();
    });

    test('it counts progress through the batch', async ({ page }) => {
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;
            const slow = () => new Promise((resolve) => setTimeout(resolve, 900));

            for (let index = 1; index <= 4; index += 1) {
                queue.enqueue({ id: 900 + index, url: '/x', title: `Track ${index}` }, slow);
            }
        });

        // Out of the whole batch: "3 of 40" says how far along this is, where
        // "37 left" says only that it is not finished.
        await expect(page.locator('[data-download-queue-count]')).toHaveText(/of 4/);
    });

    test('it lists what is waiting when opened', async ({ page }) => {
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;
            const slow = () => new Promise((resolve) => setTimeout(resolve, 1500));

            for (let index = 1; index <= 3; index += 1) {
                queue.enqueue({ id: 910 + index, url: '/x', title: `Song ${index}` }, slow);
            }
        });

        await page.evaluate(() => document.querySelector('[data-download-queue-toggle]').click());

        const list = page.locator('[data-download-queue-list]');

        await expect(list).toContainText('Song 1');
        await expect(list, 'the active one says so').toContainText(/downloading/);
    });

    test('it goes away when the queue empties', async ({ page }) => {
        await page.evaluate(() => {
            // Long enough to be observed before it finishes: a 300ms download
            // completes before the assertion runs, so the test raced itself
            // rather than the code.
            window.soundchexDownloadQueue.enqueue(
                { id: 999, url: '/x', title: 'Last' },
                () => new Promise((resolve) => setTimeout(resolve, 2000)),
            );
        });

        // Polled rather than asserted once: enqueue resolves before the event
        // that renders the row has been handled, so a bare assertion races the
        // UI rather than testing it.
        await expect.poll(
            () => page.evaluate(() => document.querySelector('[data-download-queue]')?.hidden),
            { message: 'the queue row appears', timeout: 10000 },
        ).toBe(false);

        await expect.poll(
            () => page.evaluate(() => document.querySelector('[data-download-queue]')?.hidden),
            { message: 'and goes when the queue empties', timeout: 15000 },
        ).toBe(true);
    });
});

/**
 * A queue interrupted by losing the connection.
 *
 * Going offline mid-batch failed every remaining item in turn, each costing its
 * own timeout, and left nothing to resume — so a download interrupted by a
 * tunnel had to be started again from the beginning.
 */
test.describe('pausing and resuming downloads', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true });
        await page.evaluate(() => window.soundchexDownloadQueue.clear());
    });

    test('the queue is written down as it goes', async ({ page }) => {
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;
            const slow = () => new Promise((resolve) => setTimeout(resolve, 800));

            for (let index = 1; index <= 4; index += 1) {
                queue.enqueue({ id: 800 + index, url: '/x', title: `T${index}` }, slow);
            }
        });

        // Closing the app is the same problem as losing the connection: work
        // left unfinished with nothing to pick it up from.
        await expect.poll(
            () => page.evaluate(() => window.soundchexDownloadQueue.stored().length),
            { timeout: 5000 },
        ).toBeGreaterThan(0);
    });

    test('losing the connection pauses rather than fails', async ({ page, context }) => {
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;
            const slow = () => new Promise((resolve) => setTimeout(resolve, 600));

            for (let index = 1; index <= 5; index += 1) {
                queue.enqueue({ id: 810 + index, url: '/x', title: `T${index}` }, slow);
            }
        });

        await context.setOffline(true);

        await expect.poll(
            () => page.evaluate(() => window.soundchexDownloadQueue.isPaused()),
            { message: 'the queue pauses', timeout: 10000 },
        ).toBe(true);

        // Still there to be resumed, rather than failed away.
        const remaining = await page.evaluate(() => window.soundchexDownloadQueue.stored().length);

        expect(remaining).toBeGreaterThan(0);

        await context.setOffline(false);
    });

    test('the connection returning picks it up again', async ({ page, context }) => {
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;
            const quick = () => new Promise((resolve) => setTimeout(resolve, 300));

            for (let index = 1; index <= 4; index += 1) {
                queue.enqueue({ id: 820 + index, url: '/x', title: `T${index}` }, quick);
            }
        });

        await context.setOffline(true);
        await expect.poll(
            () => page.evaluate(() => window.soundchexDownloadQueue.isPaused()),
            { timeout: 10000 },
        ).toBe(true);

        await context.setOffline(false);

        await expect.poll(
            () => page.evaluate(() => window.soundchexDownloadQueue.isPaused()),
            { message: 'it resumes on its own', timeout: 15000 },
        ).toBe(false);
    });
});

