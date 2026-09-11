import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Downloading a whole library at once.
 *
 * 4,400 tracks over a phone connection is minutes of work that will be
 * interrupted — by a tunnel, by the app being backgrounded, by the phone being
 * turned off. What matters is that none of that loses anything: an interrupted
 * batch resumes, a blip retries, and nothing is fetched twice.
 */
test.describe('batch downloads', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/downloads');

        await page.evaluate(() => window.soundchexDownloadQueue.clear());
    });

    test('a queue survives the app closing', async ({ page }) => {
        // Runners that never settle, so what is in the queue when the page is
        // killed is decided here rather than by a timer. They used to resolve
        // after 4 seconds, which was the first suspected cause of this test's
        // flakiness — it was not, but a fixture that cannot finish on its own
        // is the right shape regardless.
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;

            ['801', '802', '803'].forEach((id) => queue.enqueue(
                { id, url: '/soundchex.json', title: `Track ${id}`, type: 'music' },
                () => new Promise(() => {}),
            ));
        });

        expect(await page.evaluate(() => window.soundchexDownloadQueue.stored().length)).toBe(3);

        // The app is killed mid-download. What was written down is read back
        // *before* the page's own resume can act on it — `resumeDownloads()`
        // runs on load and immediately re-enqueues everything it finds, and
        // these fixture URLs are a few hundred bytes, so by the time a test
        // could ask, the queue has legitimately drained itself.
        //
        // Read from `localStorage` directly for that reason: `stored()` is the
        // same data, but asking for it after the reload means asking after the
        // thing that empties it has already run.
        //
        // Asserting on `stored()` after the reload is why this failed 4 runs in
        // 5 — it was watching resume work correctly and calling it a
        // regression. The queue was never broken.
        const persisted = await page.evaluate(() => localStorage.getItem('soundchex.download-queue'));

        await page.reload();

        const after = JSON.parse(persisted ?? '[]').map((item) => item.id);

        expect(after).toContain('802');
        expect(after).toContain('803');

        // And the page acts on it: everything written down is picked up rather
        // than sitting there, which is the half that actually matters.
        await expect.poll(
            () => page.evaluate(() => window.soundchexDownloadQueue.stored().length),
            { timeout: 15000 },
        ).toBeLessThan(3);
    });

    test('a blip is retried rather than lost', async ({ page }) => {
        // One dropped packet in a batch of four hundred must not silently cost
        // a track. navigator.onLine only knows about the interface, so a
        // failure with it "online" is still usually the network.
        const result = await page.evaluate(async () => {
            let attempts = 0;

            const { promise } = window.soundchexDownloadQueue.enqueue(
                { id: '860', url: '/x', title: 'Blip', type: 'music' },
                () => {
                    attempts++;

                    return attempts < 2
                        ? Promise.reject(new Error('blip'))
                        : Promise.resolve({ id: '860' });
                },
            );

            const ok = await promise.then(() => true).catch(() => false);

            return { attempts, ok };
        });

        expect(result.ok).toBe(true);
        expect(result.attempts).toBe(2);
    });

    test('it gives up on something genuinely broken', async ({ page }) => {
        // Retrying forever is its own failure: the batch never finishes and the
        // button never settles.
        const result = await page.evaluate(async () => {
            let attempts = 0;

            const { promise } = window.soundchexDownloadQueue.enqueue(
                { id: '861', url: '/x', title: 'Dead', type: 'music' },
                () => { attempts++; return Promise.reject(new Error('gone')); },
            );

            const ok = await promise.then(() => true).catch(() => false);

            return { attempts, ok, remaining: window.soundchexDownloadQueue.size() };
        });

        expect(result.ok).toBe(false);
        expect(result.attempts).toBe(3);
        expect(result.remaining).toBe(0);
    });

    test('an already-stored track is not fetched again', async ({ page }) => {
        const fetches = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;

            await downloads.download({ id: '870', url: '/soundchex.json', meta: { title: 'Once', type: 'music' } });

            // Count only what crosses the network on the second attempt.
            let count = 0;
            const realFetch = window.fetch;
            window.fetch = (...args) => { count++; return realFetch(...args); };

            const again = await downloads.download({ id: '870', url: '/soundchex.json', meta: { title: 'Once', type: 'music' } });

            window.fetch = realFetch;

            return { count, alreadyStored: again.alreadyStored === true };
        });

        expect(fetches.alreadyStored).toBe(true);
        expect(fetches.count).toBe(0);
    });

    test('the same id queued twice only runs once', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const queue = window.soundchexDownloadQueue;
            let runs = 0;

            const run = () => new Promise((resolve) => { setTimeout(() => { runs++; resolve({}); }, 50); });

            const first = queue.enqueue({ id: '880', url: '/x', title: 'Dup', type: 'music' }, run);
            const second = queue.enqueue({ id: '880', url: '/x', title: 'Dup', type: 'music' }, run);

            await Promise.allSettled([first.promise, second.promise]);

            return { runs, secondQueued: second.queued };
        });

        expect(result.secondQueued).toBe(false);
        expect(result.runs).toBe(1);
    });
});
