import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * The music screens on a real touch device profile.
 *
 * The desktop project cannot see these: several affordances are revealed by
 * `@media (hover: none)`, which a desktop viewport never matches however
 * narrow it is made. A phone-width desktop browser is not a phone.
 */
test.describe('music on a phone', () => {
    test('song rows expose their actions without hovering', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1200);

        const row = page.locator('ol li[data-long-press-menu]').first();

        await expect(row).toBeVisible();
        // A control revealed only on hover is unreachable with a finger.
        await expect(row.locator('.track-menu > summary')).toBeVisible();
    });

    test('an album header is one action row', async ({ page }) => {
        // It used to stack three: Play and Shuffle, then Download on its own
        // line, then a centred "More" floating alone.
        await signIn(page);
        await page.goto('/app/album?artist=Synthetic+Artist&album=Synthetic+Album');
        await page.waitForTimeout(1200);

        // Scoped to the header's own action row: the track rows carry play
        // buttons of their own, and catching those measures the whole page.
        const tops = await page.locator('.album-actions > *')
            .evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));

        // Every header action on the same line, within a few pixels.
        expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(12);
    });

    test('album tracks list in order', async ({ page }) => {
        // sortBy()'s array form reversed the pair on a null disc number, which
        // is what most albums have.
        await signIn(page);
        await page.goto('/app/album?artist=Synthetic+Artist&album=Synthetic+Album');
        await page.waitForTimeout(1000);

        // The row title only: the overflow menu holds a "Go to details" link
        // to the same item, and picking that up measures the menu.
        const titles = await page.locator('ol li > div > a[href*="/app/item/"]').allTextContents();

        expect(titles.map((t) => t.trim())).toEqual(['Test Tone One', 'Test Tone Two']);
    });

    test('the groupings sit above the library, not below it', async ({ page }) => {
        // They used to render at the foot of the page, above the tab bar,
        // where the navigation read as a footer.
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1200);

        const chips = await page.locator('.music-subnav').first().evaluate((el) => el.getBoundingClientRect().top);
        const list = await page.locator('ol').first().evaluate((el) => el.getBoundingClientRect().top);

        expect(chips).toBeLessThan(list);
    });

    test('music opens without a hero', async ({ page }) => {
        // A hero sells one title, which is how a film library works. It pushed
        // the library itself below the fold.
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1000);

        await expect(page.locator('.hero, [data-hero]')).toHaveCount(0);
    });

    test('filters are collapsed until asked for', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1000);

        await expect(page.locator('.browse-filters-toggle')).toBeVisible();
        await expect(page.locator('#filter-search')).toBeHidden();
    });

    test('a filtered page opens with its filters showing', async ({ page }) => {
        // Otherwise the page looks unfiltered while quietly hiding results.
        await signIn(page);
        await page.goto('/app/music?search=Tone');
        await page.waitForTimeout(1000);

        await expect(page.locator('#filter-search')).toBeVisible();
    });
});
