import { expect, test } from '@playwright/test';
import { chooseProfile, signIn } from './helpers.js';

/**
 * Access asserted in a real browser, by requesting the URL.
 *
 * The PHP suite covers the same rules at the request level. This exists for
 * what that cannot see: that the *session* a browser actually carries — set by
 * clicking through the profile picker, not by a test helper — is the one the
 * guards read. A profile switch that failed to persist would pass every PHP
 * test and still leave a member holding the owner's rights.
 */
test.describe('profile access', () => {
    test('the kid profile is refused in the admin panel by direct URL', async ({ page }) => {
        // Not "is the link hidden" — the route resolves whether or not
        // anything links to it.
        await signIn(page, 'Kid');

        for (const path of ['/admin', '/admin/music', '/admin/users']) {
            const response = await page.goto(path);

            expect(response.status(), `${path} should not be reachable`)
                .toBeGreaterThanOrEqual(400);
        }
    });

    test('the owner profile reaches the admin panel', async ({ page }) => {
        // Proves the refusal above is the cap doing its job rather than the
        // panel being broken for everyone.
        await signIn(page, 'Owner');

        const response = await page.goto('/admin');

        expect(response.status()).toBeLessThan(400);
    });

    test('a capped profile cannot open a higher rated film by direct URL', async ({ page }) => {
        await signIn(page, 'Owner');

        // Find the R-rated film's id as the owner, who can see it.
        await page.goto('/app/movie');
        const link = page.locator('a[href*="/app/item/"]').filter({ hasText: /Grown Up/i }).first();
        const href = await link.getAttribute('href');
        const id = href.match(/\/app\/item\/(\d+)/)[1];

        await chooseProfile(page, 'Kid');

        // Filtering the grid while a direct link still plays is the failure
        // that reads as working.
        for (const path of [`/app/item/${id}`, `/app/item/${id}/stream`]) {
            const response = await page.goto(path);

            expect(response.status(), `${path} should not be reachable`).toBe(404);
        }
    });

    test('a capped profile still sees titles within its rating', async ({ page }) => {
        // A cap that empties the library is not protection, it is a bug.
        await signIn(page, 'Kid');
        await page.goto('/app/movie');

        await expect(page.getByText(/Family Film/i).first()).toBeVisible();
        await expect(page.getByText(/Grown Up Film/i)).toHaveCount(0);
    });
});
