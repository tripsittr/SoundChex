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

    // setOffline persists on the context and the single-worker run reuses it: a
    // test that fails between going offline and coming back would strand the flag
    // and hang every later spec's beforeEach (S-28). Reset unconditionally.
    test.afterEach(async ({ context }) => {
        await context.setOffline(false);
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
        // things. Saying so is the difference between degraded and broken —
        // but quietly: it used to be a coloured banner, which made the app
        // look like a different, lesser application whenever the server was
        // away.
        await context.setOffline(true);
        await page.goto('/app/music').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('.offline-note')).toBeVisible();

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

    test('a single album page rebuilds from the device (S-27)', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/album?artist=flipturn&album=Heavy+Colors').catch(() => {});
        await page.waitForTimeout(2500);

        // Both of the album's tracks, on their own detail page — not a server
        // round-trip that would fail offline.
        await expect(page.locator('body')).toContainText('Offline Song A');
        await expect(page.locator('body')).toContainText('Offline Song B');

        await context.setOffline(false);
    });

    test('a single artist page rebuilds from the device (S-27)', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/artist?name=flipturn').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText('Heavy Colors');

        await context.setOffline(false);
    });

    test('search runs against the device copy', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/search?q=Offline+Song').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText('Offline Song A');

        await context.setOffline(false);
    });

    test('offline search says what it cannot search', async ({ page, context }) => {
        // Dialogue and page text are not mirrored. Results that are quietly
        // narrower than usual look like a library that has lost things.
        await context.setOffline(true);
        await page.goto('/app/search?q=Offline').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText(/subtitles and books needs a connection/i);

        await context.setOffline(false);
    });

    test('an item page rebuilds from the device', async ({ page, context }) => {
        await context.setOffline(true);
        await page.goto('/app/item/1').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText('Offline Song A');

        await context.setOffline(false);
    });

    test('the home screen rebuilds when nothing is reachable', async ({ page, context }) => {
        // Where the app landed when the server was off. /app was not a screen
        // the shell knew how to rebuild, so it showed the generic "can't reach
        // your library" page while the whole catalogue sat unread on the
        // device — the app was unusable exactly when its offline copy was the
        // only thing that mattered.
        await context.setOffline(true);
        await page.goto('/app').catch(() => {});
        await page.waitForTimeout(2500);

        await expect(page.locator('body')).toContainText('Offline Song A');
        await expect(page.locator('body')).not.toContainText("Can't reach your library");

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
