import { expect, test } from '@playwright/test';
import { appReady, signIn } from './helpers.js';

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
        await appReady(page, { rows: true });
    });

    /**
     * Starts playback and waits for the clock to actually move.
     *
     * `currentTime > 6` is the assertion on purpose: `paused: false` and
     * `readyState: 4` are exactly what a player reports when it is stuck, so
     * anything weaker would pass on a silent one.
     *
     * It is **not** a six-second wait. Chromium decodes faster than realtime
     * when nothing holds it back — a fifteen-second poll window was measured
     * reaching `currentTime: 20.6` on a thirty-second fixture. So this clears
     * in well under six seconds of wall clock, and any timing intuition built
     * on "six seconds of audio means six seconds elapsed" will be wrong.
     *
     * On failure it says what the element was doing rather than only that it
     * did not reach six. These specs spent a night frozen at `0.02322` — one
     * 1024-sample buffer at 44.1 kHz — and every hypothesis had to be paid for
     * with a fresh run because the failure itself carried no evidence. Three
     * were wrong. If it comes back, this makes the next report the last one.
     */
    const startPlaying = async (page) => {
        await page.locator('li[data-long-press-menu] button[data-play-index]').first().click();

        const state = () => page.evaluate(() => {
            const el = window.soundchexPlayer?.el;

            if (!el) return { player: false };

            return {
                currentTime: el.currentTime,
                paused: el.paused,
                readyState: el.readyState,
                networkState: el.networkState,
                error: el.error?.code ?? null,
                duration: el.duration,
                buffered: el.buffered.length ? el.buffered.end(0) : 0,
                playbackRate: el.playbackRate,
                muted: el.muted,
                volume: el.volume,
                // Which source it settled on. A blob means the downloaded copy
                // was swapped in; the network one means it was not.
                isBlob: (el.currentSrc || '').startsWith('blob:'),
                localSourceUrl: window.soundchexPlayer?.localSourceUrl ?? null,
            };
        });

        try {
            await expect.poll(
                async () => (await state()).currentTime ?? 0,
                { message: 'playback has actually started', timeout: 15000 },
            ).toBeGreaterThan(6);
        } catch (failure) {
            // Attached rather than thrown away: a stationary clock and a
            // stalled decoder look identical from the assertion alone.
            const frozen = await state();

            throw new Error(
                `playback did not advance past 6s. Element reported: ${JSON.stringify(frozen)}\n\n${failure.message}`,
            );
        }
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
        await page.waitForTimeout(1500);

        // The app must not *decide* to stop: the listener's intent is carried
        // across the navigation and a first-gesture resume is primed. Whether
        // the element has actually paused depends on the engine's autoplay
        // policy — Chromium refuses the un-gestured play() and reports paused;
        // Playwright's WebKit permits it — so `el.paused` here tests the engine,
        // not the app. `wantedPlaying` is the portable signal that the app kept
        // it playing rather than silencing it.
        const after = await page.evaluate(() => ({
            wantedPlaying: window.soundchexPlayer.wantedPlaying,
            paused: window.soundchexPlayer.el.paused,
        }));
        expect(after.wantedPlaying, 'intent to keep playing survives the navigation').toBe(true);

        // Where the engine does enforce the policy, the track is primed silently
        // until a gesture — a page that opens itself into sound is hostile.
        if (after.paused) {
            await page.mouse.click(200, 400);

            await expect.poll(
                () => page.evaluate(() => window.soundchexPlayer.el.paused),
                { message: 'a tap resumes playback', timeout: 8000 },
            ).toBe(false);
        }
    });

    test('a paused track stays paused', async ({ page }) => {
        await startPlaying(page);

        await page.evaluate(() => window.soundchexPlayer.el.pause());

        // Wait for the pause to be *persisted*, not a fixed pause. The pause
        // handler sets wantedPlaying=false and saves, but navigating before that
        // save commits carries a stale "playing" into the reader — an
        // intermittent flake on slower engines. Gate the navigation on the saved
        // intent instead.
        await expect.poll(
            () => page.evaluate(() => {
                const s = JSON.parse(sessionStorage.getItem('soundchex.player.session') ?? '{}');
                return s.paused === true;
            }),
            { message: 'the pause is saved before navigating', timeout: 4000 },
        ).toBe(true);

        await page.goto('/app/read/5');
        await page.waitForTimeout(1500);
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
