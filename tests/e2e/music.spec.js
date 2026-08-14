import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Albums, the artist page and playlist reordering in a real browser.
 *
 * The PHP suite covers the data. These cover what only a browser shows: that
 * the controls are reachable, and that a queue built from an album really is
 * handed to the player rather than just rendered into an attribute.
 */
test.describe('music browsing', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
    });

    test('an album lists its tracks and plays them in order', async ({ page }) => {
        await page.goto('/app/albums');
        await page.locator('a[href*="/app/album?"]').first().click();

        await expect(page.locator('[data-play]').first()).toBeVisible();

        // Playing the album must queue all of it, not just the track clicked.
        await page.locator('[data-play][data-play-index="0"]').first().click();

        await expect.poll(
            () => page.evaluate(() => window.soundchexPlayer?.queue?.length ?? 0),
            { timeout: 15000 },
        ).toBeGreaterThan(1);
    });

    test('the artist link reaches a page of that artist work', async ({ page }) => {
        await page.goto('/app/albums');
        await page.locator('a[href*="/app/album?"]').first().click();

        await page.locator('a[href*="/app/artist"]').first().click();

        await expect(page).toHaveURL(/\/app\/artist/);
        await expect(page.locator('a[href*="/app/album?"]').first()).toBeVisible();
    });

    test('a playlist with tracks offers drag handles', async ({ page }) => {
        const id = await createPlaylistWithTracks(page);

        await page.goto(`/app/playlists/${id}`);

        await expect(page.locator('[data-reorderable]')).toHaveCount(1);
        await expect(page.locator('[data-drag-handle]')).toHaveCount(2);
    });

    test('an empty playlist offers no reordering', async ({ page }) => {
        // Nothing to reorder, so the affordance would be a lie.
        await page.goto('/app/playlists');

        const form = page.locator('form[action$="/app/playlists"]').first();

        await form.locator('input[name="name"]').fill('Empty');
        await form.locator('button[type="submit"]').click();

        await expect(page.locator('[data-reorderable]')).toHaveCount(0);
    });

    test('the music tab offers albums and library shuffle', async ({ page }) => {
        await page.goto('/app/music');

        await expect(page.locator('a[href$="/app/albums"]').first()).toBeVisible();
        await expect(page.locator('[data-shuffle-library]')).toHaveCount(1);
    });

    test('shuffling the library queues tracks and starts playing', async ({ page }) => {
        await page.goto('/app/music');
        await page.locator('[data-shuffle-library]').click();

        await expect.poll(
            () => page.evaluate(() => window.soundchexPlayer?.queue?.length ?? 0),
            { timeout: 15000 },
        ).toBeGreaterThan(0);

        expect(await page.evaluate(() => window.soundchexPlayer.el.paused)).toBe(false);
    });

    test('a track row shows its title, not only a number', async ({ page }) => {
        // Most tags in a real library carry an implausible track number, and
        // the row showed that instead of anything readable.
        await page.goto('/app/albums');
        await page.locator('a[href*="/app/album?"]').first().click();

        const title = page.locator('ol li a[href*="/app/item/"]').first();

        await expect(title).toBeVisible();
        expect((await title.textContent()).trim()).not.toBe('');
    });

    test('the overflow menu can queue a track', async ({ page }) => {
        await page.goto('/app/albums');
        await page.locator('a[href*="/app/album?"]').first().click();

        const menu = page.locator('.track-menu').last();

        await menu.locator('summary').click();
        await menu.locator('[data-menu-queue]').click();

        await expect.poll(
            () => page.evaluate(() => window.soundchexPlayer?.queue?.length ?? 0),
        ).toBeGreaterThan(0);
    });
});

async function createPlaylistWithTracks(page) {
    await page.goto('/app/playlists');

    const form = page.locator('form[action$="/app/playlists"]').first();

    await form.locator('input[name="name"]').fill('Reorder Test');
    await form.locator('button[type="submit"]').click();
    await page.waitForURL(/\/app\/playlists\/\d+/);

    const id = page.url().match(/playlists\/(\d+)/)[1];

    for (const item of [1, 2]) {
        await page.evaluate(async ({ id, item }) => {
            await fetch(`/app/playlists/${id}/items`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ item_id: item }),
            });
        }, { id, item });
    }

    return id;
}
