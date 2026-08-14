import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Browsing with no network.
 *
 * The service worker serves offline.html when a navigation fails. That page
 * previously loaded no scripts at all, so the mirror was unreachable and
 * "offline" was a dead end even with the whole catalogue sitting on the
 * device. It now loads the library bundle from cache and rebuilds the screen.
 */
test.describe('offline browsing', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1200);

        // Warm the caches normal use would already have warmed.
        await page.evaluate(() => fetch('/build/manifest.json').then((r) => r.json()));

        await page.evaluate(async () => {
            await window.soundchexLibrary.mirror.replaceAll([
                { id: 1, type: 'music', title: 'Offline Song A', parent_id: null, playable: true, subtitle: 'flipturn', meta: { artist: 'flipturn', album: 'Heavy Colors' } },
                { id: 2, type: 'music', title: 'Offline Song B', parent_id: null, playable: true, subtitle: 'flipturn', meta: { artist: 'flipturn', album: 'Heavy Colors' } },
                { id: 3, type: 'movie', title: 'Offline Film', parent_id: null, playable: true, subtitle: '2026', meta: { release_year: 2026 } },
            ], { syncedAt: '2026-08-14T00:00:00Z' });
        });
    });

    test('the songs list rebuilds from the device', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/music').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('ol li')).toHaveCount(2);
        await expect(page.locator('body')).toContainText('Offline Song A');

        await context.setOffline(false);
    });

    test('it says the library is a local copy', async ({ page, context }) => {
        // A library that is quietly a snapshot looks like one that has lost
        // things. Saying so is the difference between degraded and broken.
        await context.setOffline(true);
        await page.goto('/app/music').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('.offline-banner')).toBeVisible();

        await context.setOffline(false);
    });

    test('a film grid rebuilds too', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/movie').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText('Offline Film');

        await context.setOffline(false);
    });

    test('albums are derived on the device', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/albums').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText('Heavy Colors');

        await context.setOffline(false);
    });

    test('online pages are still server-rendered', async ({ page }) => {
        // The takeover must never fire on a page the server drew: swapping
        // live data for a snapshot is a downgrade, not a rescue.
        await page.goto('/app/music');
        await page.waitForTimeout(1500);

        await expect(page.locator('.offline-banner')).toHaveCount(0);
    });
});
