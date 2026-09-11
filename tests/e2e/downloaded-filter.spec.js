import { expect, test } from '@playwright/test';
import { appReady, signIn } from './helpers.js';

/**
 * "Downloaded" as a filter, and as the reason a row is dimmed offline.
 *
 * Both come from IndexedDB, which the server has never been told about — so
 * neither can be a query string, and both keep working with nothing reachable.
 * That is the case they exist for.
 */
test.describe('the downloaded filter', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true, downloads: true });
    });

    const rows = (page) => page.locator('li[data-long-press-menu]');
    const box = (page) => page.locator('[data-filter-downloaded]');

    /**
     * Opens the filter panel.
     *
     * It is a `<details>`, collapsed by default — filtering is occasional, and
     * a permanent search box plus toggles pushed the library most of a screen
     * down. So the control is not clickable until the panel is open, which is
     * the app behaving correctly rather than a problem to route around.
     */
    const openFilters = async (page) => {
        const panel = page.locator('details.browse-filters-wrap');

        if (!await panel.evaluate((el) => el.open)) {
            await panel.locator('summary').click();
        }

        await box(page).waitFor({ state: 'visible', timeout: 10000 });
    };

    /** Downloads the first track, so there is something to filter to. */
    const downloadFirst = async (page) => {
        const id = await page.locator('[data-download]:not(#download-toggle)')
            .first().getAttribute('data-download');

        await page.locator(`[data-download="${id}"]:not(#download-toggle)`).click();
        await expect(page.locator(`[data-download="${id}"]:not(#download-toggle)`))
            .toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        return id;
    };

    test('the control is there and starts off', async ({ page }) => {
        await expect(box(page)).toHaveCount(1);

        await openFilters(page);
        await expect(box(page)).not.toBeChecked();
    });

    test('it hides rows this device does not hold', async ({ page }) => {
        const before = await rows(page).count();

        expect(before).toBeGreaterThan(1);

        const id = await downloadFirst(page);

        await openFilters(page);
        await box(page).check();

        // Only the downloaded row is left visible.
        await expect(rows(page).locator('visible=true')).toHaveCount(1, { timeout: 10000 });

        const shown = await page.locator('li[data-long-press-menu]:not([hidden]) [data-download]')
            .first().getAttribute('data-download');

        expect(shown).toBe(id);
    });

    test('unchecking brings everything back', async ({ page }) => {
        const before = await rows(page).count();

        await downloadFirst(page);
        await openFilters(page);
        await box(page).check();
        await expect(rows(page).locator('visible=true')).toHaveCount(1, { timeout: 10000 });

        await box(page).uncheck();

        await expect(rows(page).locator('visible=true')).toHaveCount(before, { timeout: 10000 });
    });

    test('the choice survives a reload', async ({ page }) => {
        await downloadFirst(page);
        await openFilters(page);
        await box(page).check();
        await expect(rows(page).locator('visible=true')).toHaveCount(1, { timeout: 10000 });

        await page.reload();
        await appReady(page, { rows: true, downloads: true });

        // Both the control and what it does come back.
        await openFilters(page);
        await expect(box(page)).toBeChecked();
        await expect(rows(page).locator('visible=true')).toHaveCount(1, { timeout: 10000 });
    });
});

/**
 * Offline, an undownloaded row is dimmed rather than removed.
 *
 * The metadata is worth reading when the audio is not there: knowing the
 * record exists, and that this is the track you were after, is most of what a
 * catalogue is for.
 */
test.describe('offline dimming', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true, downloads: true });
    });

    test('rows are not dimmed while the server answers', async ({ page }) => {
        await expect(page.locator('li.is-unavailable-offline')).toHaveCount(0);
    });

    test('an undownloaded row dims when the connection goes', async ({ page }) => {
        const total = await page.locator('li[data-long-press-menu]').count();

        await page.evaluate(() => {
            window.soundchexOffline = true;
            document.dispatchEvent(new CustomEvent('soundchex:went-offline'));
        });

        // Nothing is downloaded here, so every row dims — and none is hidden,
        // which is the distinction that matters.
        await expect(page.locator('li.is-unavailable-offline')).toHaveCount(total, { timeout: 10000 });
        await expect(page.locator('li[data-long-press-menu]:not([hidden])')).toHaveCount(total);
    });

    test('a downloaded row stays bright offline', async ({ page }) => {
        const id = await page.locator('[data-download]:not(#download-toggle)')
            .first().getAttribute('data-download');

        await page.locator(`[data-download="${id}"]:not(#download-toggle)`).click();
        await expect(page.locator(`[data-download="${id}"]:not(#download-toggle)`))
            .toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        await page.evaluate(() => {
            window.soundchexOffline = true;
            document.dispatchEvent(new CustomEvent('soundchex:went-offline'));
        });

        await expect(page.locator('li.is-unavailable-offline')).not.toHaveCount(0, { timeout: 10000 });

        const dimmed = await page.locator(`li:has([data-download="${id}"])`)
            .first().getAttribute('class');

        expect(dimmed).not.toContain('is-unavailable-offline');
    });

    test('coming back online undims everything', async ({ page }) => {
        await page.evaluate(() => {
            window.soundchexOffline = true;
            document.dispatchEvent(new CustomEvent('soundchex:went-offline'));
        });

        await expect(page.locator('li.is-unavailable-offline')).not.toHaveCount(0, { timeout: 10000 });

        await page.evaluate(() => {
            window.soundchexOffline = false;
            document.dispatchEvent(new CustomEvent('soundchex:back-online'));
        });

        await expect(page.locator('li.is-unavailable-offline')).toHaveCount(0, { timeout: 10000 });
    });
});
