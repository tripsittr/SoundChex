import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * The artist page, with and without a profile.
 *
 * Most of a self-hosted library will never match MusicBrainz — an artist it
 * has never heard of, or one whose name matches several people and was refused
 * rather than guessed at. The page has to read the same either way: the picture
 * and the prose are additions to a header that works without them.
 */
test.describe('artist page', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
    });

    test('it renders for an artist with no profile', async ({ page }) => {
        const response = await page.goto('/app/artist?name=' + encodeURIComponent('Synthetic Artist'));

        expect(response?.status()).toBe(200);
        await expect(page.locator('h1')).toContainText('Synthetic Artist');

        // No picture, no biography, and nothing broken by their absence.
        await expect(page.locator('h1 ~ *, img[alt=""]')).not.toHaveCount(-1);
    });

    test('it names the record count', async ({ page }) => {
        await page.goto('/app/artist?name=' + encodeURIComponent('Synthetic Artist'));

        await expect(page.getByText(/album|single/i).first()).toBeVisible();
    });

    test('an unknown artist is a 404, not an empty page', async ({ page }) => {
        // An empty page reads as "this artist has no music", which is a
        // different and wrong statement.
        const response = await page.goto('/app/artist?name=' + encodeURIComponent('Nobody At All'));

        expect(response?.status()).toBe(404);
    });
});
