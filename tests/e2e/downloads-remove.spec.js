import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Removing downloads.
 *
 * The per-row button was reported as doing nothing. It had no error handling
 * at all: a failed remove left the button disabled with no explanation, which
 * is indistinguishable from a button that is not wired up — and removes were
 * failing because the IndexedDB connection had been closed by iOS.
 *
 * "Remove all" is destructive and irreversible, so what it asks before acting
 * matters as much as what it does.
 */
test.describe('removing downloads', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/downloads');

        await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;

            await downloads.download({ id: '901', url: '/soundchex.json', meta: { title: 'One', type: 'music' } });
            await downloads.download({ id: '902', url: '/soundchex.json', meta: { title: 'Two', type: 'music' } });
        });

        await page.reload();
        await expect(page.locator('#downloads-list > *')).toHaveCount(2);
    });

    test('the row button removes just that file', async ({ page }) => {
        await page.locator('#downloads-list button', { hasText: 'Remove' }).first().click();

        await expect(page.locator('#downloads-list > *')).toHaveCount(1);

        // Gone from storage, not only from the screen.
        expect(await page.evaluate(() => window.soundchexDownloads.list().then((r) => r.length))).toBe(1);
    });

    test('removing everything asks first, and says what will go', async ({ page }) => {
        await page.locator('#downloads-remove-all').click();

        const dialog = page.locator('#downloads-confirm');
        await expect(dialog).toBeVisible();

        // Naming the count is what makes this a decision rather than a guess.
        await expect(page.locator('#downloads-confirm-detail')).toContainText('2 files');
    });

    test('cancelling removes nothing', async ({ page }) => {
        await page.locator('#downloads-remove-all').click();
        await page.locator('#downloads-confirm-cancel').click();

        await expect(page.locator('#downloads-confirm')).toBeHidden();
        await expect(page.locator('#downloads-list > *')).toHaveCount(2);
    });

    test('confirming clears the device', async ({ page }) => {
        await page.locator('#downloads-remove-all').click();
        await page.locator('#downloads-confirm-remove').click();

        await expect(page.locator('#downloads-confirm')).toBeHidden();
        await expect(page.locator('#downloads-empty')).toBeVisible();

        expect(await page.evaluate(() => window.soundchexDownloads.list().then((r) => r.length))).toBe(0);
    });

    test('the remove-all button is hidden when there is nothing to remove', async ({ page }) => {
        await page.locator('#downloads-remove-all').click();
        await page.locator('#downloads-confirm-remove').click();

        await expect(page.locator('#downloads-empty')).toBeVisible();
        await expect(page.locator('#downloads-remove-all')).toBeHidden();
    });
});
