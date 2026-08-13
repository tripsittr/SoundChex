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
