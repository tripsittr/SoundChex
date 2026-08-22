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
        await page.evaluate(() => {
            const queue = window.soundchexDownloadQueue;

            ['801', '802', '803'].forEach((id) => queue.enqueue(
                { id, url: '/soundchex.json', title: `Track ${id}`, type: 'music' },
                () => new Promise((resolve) => { setTimeout(() => resolve({ id }), 4000); }),
            ));
        });

        expect(await page.evaluate(() => window.soundchexDownloadQueue.stored().length)).toBe(3);

        // The app is killed mid-download.
        await page.reload();

        // What was still waiting is written down and picked up again. The one
        // in flight is gone, which is correct — its bytes went with the page.
        const after = await page.evaluate(() => window.soundchexDownloadQueue.stored().map((i) => i.id));

        expect(after).toContain('802');
        expect(after).toContain('803');
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
