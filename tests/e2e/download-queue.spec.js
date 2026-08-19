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
