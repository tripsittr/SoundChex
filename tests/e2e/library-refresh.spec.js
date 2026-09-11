import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Bringing an open page up to date.
 *
 * The mirror syncs on launch and on returning to the foreground, and a fresh
 * navigation always queries the server. None of that covers the screen someone
 * is currently looking at: during testing a scan imported 114 tracks in an hour
 * and not one appeared until the page was left and returned to.
 */
test.describe('refreshing an open page', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.locator('main').waitFor({ timeout: 15000 });

        // The mirror syncs on launch, and on a fresh database that first sync
        // is a *full* one — `{ status: 'synced', count: 5, full: true }` — so
        // it legitimately redraws the page. A test that dispatches its own
        // synthetic event before that lands measures the real sync's redraw
        // and blames the synthetic one, which is what "a sync that changed
        // nothing does not redraw" was failing on.
        //
        // Waiting for the sync alone is not enough: the redraw is debounced
        // SETTLE (1200ms) behind it. So wait for the sync, then for the
        // redraw it schedules, and only then start measuring.
        await page.waitForFunction(
            () => window.__soundchexSynced === true,
            null,
            { timeout: 20000 },
        );

        await page.waitForTimeout(2000);
    });

    test('a sync that changed nothing does not redraw', async ({ page }) => {
        // The common case by far. Redrawing on every sync would replace the
        // list every thirty seconds for nothing.
        let redrawn = false;
        await page.exposeFunction('markRedrawn', () => { redrawn = true; });
        await page.evaluate(() => document.addEventListener('soundchex:page-refreshed', () => window.markRedrawn()));

        await page.evaluate(() => document.dispatchEvent(
            new CustomEvent('soundchex:synced', { detail: { updated: 0, removed: 0, full: false } }),
        ));

        await page.waitForTimeout(2000);

        expect(redrawn).toBe(false);
    });

    test('a sync that brought new items redraws the list', async ({ page }) => {
        const refreshed = page.evaluate(() => new Promise((resolve) => {
            document.addEventListener('soundchex:page-refreshed', () => resolve(true), { once: true });
            setTimeout(() => resolve(false), 8000);
        }));

        await page.evaluate(() => document.dispatchEvent(
            new CustomEvent('soundchex:synced', { detail: { updated: 3, removed: 0, full: false } }),
        ));

        expect(await refreshed).toBe(true);
    });

    test('it keeps the reader where they were', async ({ page }) => {
        // Losing someone's place in a list of thousands is more disruptive
        // than the stale row it was fixing.
        await page.evaluate(() => window.scrollTo(0, 400));

        const refreshed = page.evaluate(() => new Promise((resolve) => {
            document.addEventListener('soundchex:page-refreshed', () => resolve(true), { once: true });
            setTimeout(() => resolve(false), 8000);
        }));

        await page.evaluate(() => document.dispatchEvent(
            new CustomEvent('soundchex:synced', { detail: { updated: 2, removed: 0, full: false } }),
        ));

        await refreshed;

        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(200);
    });

    test('the player survives a redraw', async ({ page }) => {
        // <main> is replaced, and the bar and player object live outside it.
        // Reloading the page instead would stop playback, which is the whole
        // reason this is not location.reload().
        const before = await page.evaluate(() => !!window.soundchexPlayer);

        const refreshed = page.evaluate(() => new Promise((resolve) => {
            document.addEventListener('soundchex:page-refreshed', () => resolve(true), { once: true });
            setTimeout(() => resolve(false), 8000);
        }));

        await page.evaluate(() => document.dispatchEvent(
            new CustomEvent('soundchex:synced', { detail: { updated: 1, removed: 0, full: false } }),
        ));

        await refreshed;

        expect(await page.evaluate(() => !!window.soundchexPlayer)).toBe(before);
        await expect(page.locator('#now-playing')).toHaveCount(1);
    });
});
