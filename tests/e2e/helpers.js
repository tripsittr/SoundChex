import { expect } from '@playwright/test';

/** The account the E2eSeeder creates. Synthetic — no real credentials here. */
export const ACCOUNT = {
    email: 'household@example.test',
    password: 'password',
};

/**
 * Signs in and lands on a profile.
 *
 * The household shares one login, so signing in is only half of it — the
 * profile is what carries permissions and the rating cap.
 */
export async function signIn(page, profile = 'Owner') {
    await page.goto('/login');

    await page.fill('input[name="email"]', ACCOUNT.email);
    await page.fill('input[name="password"]', ACCOUNT.password);
    await page.click('button[type="submit"]');

    await page.waitForURL((url) => !url.pathname.endsWith('/login'));

    await chooseProfile(page, profile);
}

/**
 * Picks a profile if the picker is showing, or switches to it if not.
 */
export async function chooseProfile(page, name) {
    if (!page.url().includes('/profiles')) {
        await page.goto('/profiles');
    }

    const tile = page.getByRole('button', { name: new RegExp(name, 'i') })
        .or(page.getByRole('link', { name: new RegExp(name, 'i') }))
        .first();

    await expect(tile).toBeVisible({ timeout: 10000 });
    await tile.click();

    await page.waitForURL((url) => !url.pathname.includes('/profiles'), { timeout: 10000 });
}

/**
 * Waits for the media centre to be ready to drive.
 *
 * Replaces a fixed sleep after every goto. Those were sized for the slowest
 * observed boot, so every test paid the worst case whether it needed to or not
 * — across ~40 of them the suite spent over a minute doing nothing at all.
 * This returns as soon as the thing being waited for is actually there.
 *
 * Waits on the library module rather than a DOM element: it is what the tests
 * reach for, and the last of the bundles to arrive.
 */
export async function appReady(page, { rows = false, downloads = false } = {}) {
    await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

    if (rows) {
        // Server-rendered, so their presence means painted rather than merely
        // booted.
        await page.waitForSelector('li[data-long-press-menu]', { timeout: 20000 });
    }

    if (downloads) {
        // `paintIconDownloadStates()` reads IndexedDB and then writes a state
        // onto every download button, so it lands after rows exist. A test
        // that sets a state before it resolves has that state overwritten —
        // which read as "the stylesheet shows the wrong glyph" rather than as
        // a race. Waits for the repaint to have run at least once.
        await page.waitForFunction(
            () => window.__soundchexDownloadsPainted === true,
            null,
            { timeout: 20000 },
        );
    }
}
