import { expect, test } from '@playwright/test';
import { appReady, signIn } from './helpers.js';

/**
 * The settings page for whoever is watching.
 *
 * Two of these preferences cannot be honoured by a web page alone: biometric
 * unlock needs Face ID and notifications need the OS to grant permission. Both
 * are Tauri plugins and absent in a browser, so the page must hide what it
 * cannot deliver rather than offer a toggle that silently does nothing.
 */
test.describe('profile settings', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/settings');
    });

    test('every group renders', async ({ page }) => {
        await expect(page.locator('h1')).toHaveText('Settings');

        for (const group of ['Playback', 'Security', 'Notifications', 'Quality of life', 'Profile PIN']) {
            await expect(page.locator('.settings-group__title', { hasText: group })).toBeVisible();
        }
    });

    test('notifications are off until asked for', async ({ page }) => {
        // The OS prompt is shown once per install, and asking before anyone has
        // expressed interest is how an app gets denied permanently.
        await expect(page.locator('[data-notification-master]')).not.toBeChecked();
    });

    test('a preference survives a save', async ({ page }) => {
        const toggle = () => page.locator('input[name="autoplay_next"][type="checkbox"]');

        // Asserted from whatever the current value is rather than assuming the
        // default. These settings persist, and an earlier run of this very test
        // leaves the opposite value behind — so an assumed starting state makes
        // the test pass once and fail every time after.
        const before = await toggle().isChecked();

        await toggle().setChecked(!before);
        await page.locator('.settings-save').first().click();

        await expect(page.locator('.settings-flash')).toContainText(/saved/i);
        await expect(toggle()).toBeChecked({ checked: !before });

        // Put back, so the next run starts where this one did.
        await toggle().setChecked(before);
        await page.locator('.settings-save').first().click();
        await expect(toggle()).toBeChecked({ checked: before });
    });

    test('biometric is hidden in a browser, with a reason', async ({ page }) => {
        // A disabled toggle invites people to keep trying it; an explanation
        // does not.
        await expect(page.locator('[data-biometric-row]')).toBeHidden();
        await expect(page.locator('[data-biometric-absent]')).toBeVisible();
    });

    test('turning notifications on in a browser explains rather than failing silently', async ({ page }) => {
        await page.locator('[data-notification-master]').check();

        await expect(page.locator('[data-notification-note]')).toContainText(/app/i);
    });

    test('it is reachable from the account menu', async ({ page }) => {
        await page.goto('/app/music');
        await appReady(page, { rows: true });

        const link = page.locator('a[href$="/app/settings"]').first();

        // Every profile needs this: most have no admin access at all, so the
        // admin panel is not a route to it.
        await expect(link).toHaveCount(1);
    });
});
