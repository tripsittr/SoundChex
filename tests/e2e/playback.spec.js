import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * The bug this exists for: music stopped whenever you changed page.
 *
 * It cannot be caught by a unit test. An SPA swap replaces document.body, so
 * anything depending on surviving that replacement is only observable in a
 * real browser doing a real navigation. The player is a window singleton
 * specifically so it outlives the swap.
 *
 * Note which links these use. `resources/js/navigate.js` opts links into SPA
 * navigation at runtime and deliberately excludes /app/read/*, /app/watch/*,
 * /admin/* and downloads — those do a full page load, which legitimately
 * tears the player down. Testing playback across an excluded link would be
 * asserting the opposite of the intended behaviour.
 */
test.describe('playback across navigation', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
    });

    test('audio keeps playing across an in-app navigation', async ({ page }) => {
        await page.goto('/app');
        await startFirstTrack(page);

        const before = await currentTime(page);
        expect(before).toBeGreaterThan(0);

        await navigateWithinApp(page, '/app/music');

        await expect.poll(() => currentTime(page), {
            message: 'audio should still be advancing after navigating',
            timeout: 8000,
        }).toBeGreaterThan(before);

        expect(await paused(page)).toBe(false);
    });

    test('the now-playing bar survives the navigation', async ({ page }) => {
        await page.goto('/app');
        await startFirstTrack(page);

        await expect(page.locator('#now-playing')).toBeVisible();

        await navigateWithinApp(page, '/app/music');

        await expect(page.locator('#now-playing')).toBeVisible();
        await expect(page.locator('#now-playing')).toContainText('Test Tone');
    });

    test('the player is not rebuilt on each page', async ({ page }) => {
        // The singleton was rebuilt on every page at one point, which stacked
        // players and played tracks over each other.
        await page.goto('/app');
        await startFirstTrack(page);

        await page.evaluate(() => { window.__playerAtStart = window.soundchexPlayer; });

        await navigateWithinApp(page, '/app/music');
        await navigateWithinApp(page, '/app');

        expect(await page.evaluate(() => window.soundchexPlayer === window.__playerAtStart))
            .toBe(true);
        expect(await page.evaluate(() => document.querySelectorAll('audio').length))
            .toBeLessThanOrEqual(1);
        expect(await paused(page)).toBe(false);
    });

    test('a full page load does stop playback, as the design accepts', async ({ page }) => {
        // Pinning the boundary rather than pretending it does not exist.
        // Downloads and the reader are excluded from SPA navigation on
        // purpose, so entering them starts a fresh player.
        await page.goto('/app');
        await startFirstTrack(page);

        await page.goto('/app/downloads');
        await page.waitForLoadState('domcontentloaded');

        expect(await currentTime(page)).toBe(0);
    });
});

/**
 * Follows an in-app link and confirms it was an SPA swap, not a reload.
 *
 * A reload would satisfy a naive URL assertion while destroying the very
 * thing under test, so the document identity is checked too.
 */
async function navigateWithinApp(page, path) {
    await page.evaluate(() => { window.__swapMarker = true; });

    // Hrefs are rendered absolute, so match on the ending rather than equality.
    const link = page.locator(`a[href$="${path}"]`).filter({ visible: true }).first();

    await link.scrollIntoViewIfNeeded();
    await link.click();
    await page.waitForURL(new RegExp(`${path}$`));

    expect(
        await page.evaluate(() => window.__swapMarker === true),
        `navigating to ${path} should be an SPA swap, not a full reload`,
    ).toBe(true);
}

async function startFirstTrack(page) {
    // The play button only appears on hover and only at desktop widths — it is
    // `hidden ... md:flex` with `opacity-0 group-hover:opacity-100`. Clicking
    // it blind hits an invisible element.
    const trigger = page.locator('[data-play]').first();

    await trigger.scrollIntoViewIfNeeded();
    await trigger.hover();
    await trigger.click();

    await expect.poll(() => currentTime(page), {
        message: 'playback should start',
        timeout: 15000,
    }).toBeGreaterThan(0);
}

function currentTime(page) {
    return page.evaluate(() => window.soundchexPlayer?.el?.currentTime ?? 0);
}

function paused(page) {
    return page.evaluate(() => window.soundchexPlayer?.el?.paused);
}
