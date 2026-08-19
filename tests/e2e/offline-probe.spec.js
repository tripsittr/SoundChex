import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Reaching data that lives on the server's origin.
 *
 * IndexedDB is partitioned per origin. The app syncs the catalogue while
 * running on the server's origin; this screen runs on tauri://localhost and
 * cannot see that database at all — so a device with a fully synced library was
 * told there was nothing saved on it, and the embedded shell could only ever
 * render from an empty database.
 *
 * The shell asks that origin, through a probe page cached by its service
 * worker, and goes there when the answer is yes.
 */
test.describe('finding a synced library on the server origin', () => {
    test('the probe reports a count from the origin that holds the data', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

        await page.evaluate(() => window.soundchexLibrary.mirror.replaceAll([
            { id: 1, type: 'music', title: 'Stored Song', parent_id: null, playable: true, subtitle: 'x', meta: {} },
        ], { syncedAt: '2026-08-19T00:00:00Z' }));

        // Same page the shell frames, asked the same question.
        const reported = await page.evaluate(() => new Promise((resolve) => {
            const frame = document.createElement('iframe');

            window.addEventListener('message', function handler(event) {
                if (event.data?.type !== 'soundchex:mirror-count') return;

                window.removeEventListener('message', handler);
                frame.remove();
                resolve(event.data.count);
            });

            frame.style.display = 'none';
            frame.src = '/offline-probe.html';
            document.body.append(frame);

            setTimeout(() => resolve('timed out'), 8000);
        }));

        expect(reported, 'the probe sees the mirror on its own origin').toBe(1);
    });

    test('an empty origin reports zero rather than hanging', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

        await page.evaluate(() => window.soundchexLibrary.mirror.clear());

        const reported = await page.evaluate(() => new Promise((resolve) => {
            const frame = document.createElement('iframe');

            window.addEventListener('message', function handler(event) {
                if (event.data?.type !== 'soundchex:mirror-count') return;

                window.removeEventListener('message', handler);
                frame.remove();
                resolve(event.data.count);
            });

            frame.style.display = 'none';
            frame.src = '/offline-probe.html';
            document.body.append(frame);

            // A probe that never answers is the freeze this replaced.
            setTimeout(() => resolve('timed out'), 8000);
        }));

        expect(reported).toBe(0);
    });
});
