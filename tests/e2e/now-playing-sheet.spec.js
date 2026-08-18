import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * The full-screen player.
 *
 * The player has supported shuffle, repeat and a queue since it was written;
 * none of it had a UI. These assert the controls drive the real player rather
 * than only looking right — a sheet whose buttons paint themselves but move
 * nothing would pass a screenshot test and fail a user.
 */
test.describe('now-playing sheet', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app');
        await startPlaying(page);
    });

    test('tapping the bar opens it without leaving the page', async ({ page }) => {
        // The bar title is an <a> to the item page. Tapping it must open the
        // player instead — SPA navigation would otherwise swallow the click in
        // the capture phase and navigate away.
        const before = page.url();

        await page.locator('#np-link').click();

        await expect(page.locator('#np-sheet')).not.toHaveClass(/translate-y-full/);
        expect(page.url()).toBe(before);
        await expect(page.locator('#np-sheet-title')).toHaveText(/Test Tone/);
    });

    test('the queue lists what is actually queued', async ({ page }) => {
        await page.locator('#np-link').click();
        await page.locator('#np-sheet-queue-toggle').click();

        await expect(page.locator('#np-sheet-queue li')).toHaveCount(
            await page.evaluate(() => window.soundchexPlayer.queue.length),
        );
    });

    test('shuffle and repeat drive the player, not just the icon', async ({ page }) => {
        await page.locator('#np-link').click();

        await page.locator('#np-sheet-shuffle').click();
        expect(await page.evaluate(() => window.soundchexPlayer.shuffle)).toBe(true);

        await page.locator('#np-sheet-repeat').click();
        expect(await page.evaluate(() => window.soundchexPlayer.repeat)).not.toBe('off');
    });

    test('closing it leaves playback alone', async ({ page }) => {
        // The sheet is a view onto the player, not a second player.
        await page.locator('#np-link').click();
        await page.locator('#np-sheet-close').click();

        await expect(page.locator('#np-sheet')).toHaveClass(/translate-y-full/);

        // Polled rather than read once. A track that has only just started can
        // still be buffering, and `paused` flickers true for a moment during
        // that — asserting an instant made this fail roughly one run in ten
        // for a reason that had nothing to do with the sheet.
        await expect.poll(
            () => page.evaluate(() => window.soundchexPlayer.el.paused),
            { message: 'closing the sheet should not stop playback', timeout: 5000 },
        ).toBe(false);
    });

    test('it is inert while closed', async ({ page }) => {
        // An off-screen sheet still holds focusable buttons; tabbing into an
        // invisible dialog is a trap for keyboard and screen-reader users.
        await expect(page.locator('#np-sheet')).toHaveAttribute('inert', '');
        await expect(page.locator('#np-sheet')).toHaveAttribute('aria-hidden', 'true');
    });

    test('the hidden bar does not cover the mobile navigation', async ({ page }) => {
        // Before anything had played, the "hidden" bar sat directly over the
        // tab bar and swallowed its taps. translate-y-full moves the bar down
        // by its own height, which stopped being enough once it was raised to
        // clear the tabs.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/music');
        await page.waitForTimeout(800);

        const clickable = await page.evaluate(() => {
            const bar = document.getElementById('now-playing');
            const tabs = document.querySelector('.mobile-tabs');
            const box = tabs.getBoundingClientRect();
            const atCentre = document.elementFromPoint(
                box.left + box.width / 2,
                box.top + box.height / 2,
            );

            return !bar.contains(atCentre);
        });

        expect(clickable).toBe(true);
    });

    test('the bar clears the tab bar once something plays', async ({ page }) => {
        // And the other half: visible, but not on top of the navigation.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/music');
        await page.waitForTimeout(800);
        await page.locator('ol li [data-play]').first().click();
        await page.waitForTimeout(2000);

        const overlap = await page.evaluate(() => {
            const bar = document.getElementById('now-playing').getBoundingClientRect();
            const tabs = document.querySelector('.mobile-tabs').getBoundingClientRect();

            return bar.bottom > tabs.top + 0.5;
        });

        expect(overlap).toBe(false);
    });
});

async function startPlaying(page) {
    const trigger = page.locator('[data-play]').first();

    await trigger.scrollIntoViewIfNeeded();
    await trigger.hover();
    await trigger.click();

    await expect.poll(
        () => page.evaluate(() => window.soundchexPlayer?.el?.currentTime ?? 0),
        { timeout: 15000 },
    ).toBeGreaterThan(0);
}
