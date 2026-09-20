import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Saying when the connection goes and comes back.
 *
 * The app coped with being offline in silence: the mirror rendered, downloads
 * played, writes queued, and nothing said any of it was happening. A page from
 * the device looked identical to one from the server, so the moment something
 * did not work there was no way to tell whether the app was broken or the
 * network was.
 */
test.describe('connection toasts', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.locator('#soundchex-toast, main').first().waitFor({ timeout: 15000 });
    });

    // setOffline persists on the browser context, and a single-worker run reuses
    // it: a test that goes offline and does not come back leaves every later
    // spec's `goto` hanging in its own beforeEach — the cause of the cascade
    // where a whole project failed only in a full run (S-28). Reset
    // unconditionally, so no test has to remember and a mid-test failure can't
    // strand the flag either.
    test.afterEach(async ({ page }) => {
        await page.context().setOffline(false);
    });

    test('going offline says so', async ({ page }) => {
        await page.context().setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));

        const toast = page.locator('#soundchex-toast[data-visible="true"]');

        await expect(toast).toBeVisible({ timeout: 8000 });
        await expect(toast).toContainText(/offline/i);

        // Warned rather than alarmed: the app still works from the device.
        await expect(toast).toHaveAttribute('data-tone', 'warn');
    });

    test('coming back says so too', async ({ page }) => {
        await page.context().setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await expect(page.locator('#soundchex-toast')).toContainText(/offline/i, { timeout: 8000 });

        await page.context().setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));

        await expect(page.locator('#soundchex-toast')).toContainText(/back online/i, { timeout: 8000 });
    });

    test('it does not announce a state it is already in', async ({ page }) => {
        // Browsers fire these events liberally. A toast on every one would be
        // noise, and noise is what gets ignored.
        await page.evaluate(() => {
            window.dispatchEvent(new Event('online'));
            window.dispatchEvent(new Event('online'));
            window.dispatchEvent(new Event('online'));
        });

        await page.waitForTimeout(1200);

        await expect(page.locator('#soundchex-toast[data-visible="true"]')).toHaveCount(0);
    });
});
