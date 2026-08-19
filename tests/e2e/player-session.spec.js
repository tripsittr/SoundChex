import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Music surviving a full page load.
 *
 * The reader is a standalone document — deliberately, because an e-reader wants
 * the whole viewport rather than the media centre's nav — so opening a book was
 * a full page load that destroyed the player object and stopped whatever was
 * playing. The player is memory-only, so any non-SPA navigation did this.
 *
 * The queue, position and intent are written to sessionStorage and picked up by
 * the next page. A fresh document has no user gesture, so the browser refuses
 * to start audio on its own — the track is primed silently and the first tap
 * resumes it.
 */
test.describe('playback across a full page load', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1500);
    });

    const startPlaying = async (page) => {
        await page.locator('li[data-long-press-menu] button[data-play]').first().click();

        await expect.poll(
            () => page.evaluate(() => window.soundchexPlayer?.el?.currentTime ?? 0),
            { message: 'playback has actually started', timeout: 15000 },
        ).toBeGreaterThan(6);
    };

    test('the queue and position survive into the reader', async ({ page }) => {
        await startPlaying(page);

        const before = await page.evaluate(() => ({
            title: window.soundchexPlayer.current().title,
            time: window.soundchexPlayer.el.currentTime,
        }));

        await page.goto('/app/read/5');
        await page.waitForTimeout(1500);

        const after = await page.evaluate(() => ({
            hasPlayer: !!window.soundchexPlayer,
            title: window.soundchexPlayer?.current()?.title ?? null,
            time: window.soundchexPlayer?.el?.currentTime ?? 0,
        }));

        expect(after.hasPlayer, 'the player exists on the reader page').toBe(true);
        expect(after.title, 'the same track').toBe(before.title);
        // Within a couple of seconds: the position is written about once a
        // second, so it lands near where playback was rather than exactly on it.
        expect(Math.abs(after.time - before.time)).toBeLessThan(3);
    });

    test('the first tap in the reader resumes it', async ({ page }) => {
        await startPlaying(page);
        await page.goto('/app/read/5');
        await page.waitForTimeout(1200);

        // Silent until asked: a page that opens itself into sound is hostile,
        // and the browser refuses it anyway without a gesture.
        expect(await page.evaluate(() => window.soundchexPlayer.el.paused)).toBe(true);

        await page.mouse.click(200, 400);

        await expect.poll(
            () => page.evaluate(() => window.soundchexPlayer.el.paused),
            { message: 'a tap resumes playback', timeout: 8000 },
        ).toBe(false);
    });

    test('a paused track stays paused', async ({ page }) => {
        await startPlaying(page);

        await page.evaluate(() => window.soundchexPlayer.el.pause());
        await page.waitForTimeout(400);

        await page.goto('/app/read/5');
        await page.waitForTimeout(1200);
        await page.mouse.click(200, 400);
        await page.waitForTimeout(800);

        // A page load must not turn a deliberate pause into playback.
        expect(await page.evaluate(() => window.soundchexPlayer.el.paused)).toBe(true);
    });

    test('the now-playing bar is present on the reader', async ({ page }) => {
        await startPlaying(page);
        await page.goto('/app/read/5');

        // Somewhere to see and control the music from while reading.
        await expect(page.locator('#now-playing')).toBeAttached();
    });
});
