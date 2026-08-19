import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Downloading on WebKit.
 *
 * Downloads worked in Chrome and never on the phone, because Safari aborts an
 * IndexedDB transaction that stores a Blob — and does it with a *null* error,
 * so the failure carried no message and the bytes just written were rolled
 * back. Records are `{ buffer, type }` now.
 *
 * This has to run on WebKit: the desktop project stores a Blob happily and
 * would have passed against the broken code, which is exactly how the bug
 * survived four rounds of offline work.
 */
test.describe('downloads on a phone', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1200);
    });

    test('a track downloads and is playable from storage', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;

            await downloads.download({
                id: 1,
                url: '/app/item/1/stream',
                meta: { title: 'Test Tone One', type: 'music' },
            });

            return {
                stored: (await downloads.list()).length,
                playable: !!(await downloads.localUrl(1)),
                reported: await downloads.isDownloaded(1),
            };
        });

        expect(result.stored).toBe(1);
        expect(result.playable).toBe(true);
        expect(result.reported).toBe(true);
    });

    test('what was downloaded survives a reload', async ({ page }) => {
        // The whole point: storage that outlives the page.
        await page.evaluate(() => window.soundchexDownloads.download({
            id: 1,
            url: '/app/item/1/stream',
            meta: { title: 'Test Tone One', type: 'music' },
        }));

        await page.reload();
        await page.waitForTimeout(1200);

        expect(await page.evaluate(() => window.soundchexDownloads.isDownloaded(1))).toBe(true);
    });

    test('removing a download frees it', async ({ page }) => {
        const after = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;

            await downloads.download({ id: 1, url: '/app/item/1/stream', meta: { title: 'T', type: 'music' } });
            await downloads.remove(1);

            return downloads.isDownloaded(1);
        });

        expect(after).toBe(false);
    });
});

/**
 * The download buttons themselves.
 *
 * The tests above drive window.soundchexDownloads directly, which is how every
 * icon button in the app stayed inert without a single test noticing: song rows
 * and the now-playing sheet render [data-download] buttons, and nothing was
 * bound to them. setupDownloadButton() binds one element, #download-toggle, and
 * reads different attributes entirely.
 *
 * These click what the user clicks.
 */
test.describe('download buttons', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1500);
    });

    test('the icon button in a song row downloads the track', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();

        await expect(button).toHaveAttribute('data-state', 'idle');

        const id = await button.getAttribute('data-download');

        await button.click();

        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        // The bytes, not just the button state: a handler that only repaints
        // its own element would pass an assertion on the label alone.
        const stored = await page.evaluate(
            (itemId) => window.soundchexDownloads.isDownloaded(itemId),
            id,
        );

        expect(stored, 'the track is on the device').toBe(true);
    });

    test('tapping a stored track removes it, once confirmed', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();
        const id = await button.getAttribute('data-download');

        await button.click();
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        page.once('dialog', (dialog) => dialog.accept());
        await button.click();
        await expect(button).toHaveAttribute('data-state', 'idle');

        const stored = await page.evaluate(
            (itemId) => window.soundchexDownloads.isDownloaded(itemId),
            id,
        );

        expect(stored, 'the track is gone from the device').toBe(false);
    });

    test('declining the confirmation keeps the download', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();
        const id = await button.getAttribute('data-download');

        await button.click();
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        // Dismissed, not accepted: the audio is deleted from the device, and
        // the same button both downloads and removes — so a mis-tap must not
        // throw away a file downloaded precisely for being about to go offline.
        page.once('dialog', (dialog) => dialog.dismiss());
        await button.click();
        await page.waitForTimeout(500);

        await expect(button).toHaveAttribute('data-state', 'stored');

        const stored = await page.evaluate(
            (itemId) => window.soundchexDownloads.isDownloaded(itemId),
            id,
        );

        expect(stored, 'the track is still on the device').toBe(true);
    });

    test('a stored track is marked as stored on a later visit', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();

        await button.click();
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        // Without repainting on navigation a downloaded track shows an idle
        // icon, which invites downloading the same file twice.
        await page.goto('/app');
        await page.goto('/app/music');
        await page.waitForTimeout(1500);

        await expect(page.locator('[data-download]:not(#download-toggle)').first())
            .toHaveAttribute('data-state', 'stored');
    });
});

/**
 * What the button looks like in each state.
 *
 * The handler writes data-state and CSS picks the glyph, so these assert the
 * two agree — a state the stylesheet has no rule for shows an empty box, and a
 * download with no visible progress is indistinguishable from one that never
 * started.
 */
test.describe('download button states', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1500);
    });

    // The button transitions colour, so a read taken immediately after a state
    // change catches an interpolated value rather than the rule's own.
    const settled = async (page, button) => {
        await page.waitForTimeout(400);

        return button.evaluate((el) => ({
            colour: getComputedStyle(el).color,
            icons: [...el.querySelectorAll('[data-icon]')]
                .filter((icon) => getComputedStyle(icon).display !== 'none')
                .map((icon) => icon.dataset.icon),
        }));
    };

    test('exactly one glyph shows per state', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();

        for (const state of ['idle', 'downloading', 'stored', 'failed']) {
            await button.evaluate((el, value) => { el.dataset.state = value; }, state);

            const { icons } = await settled(page, button);

            expect(icons, `${state} shows only its own glyph`).toEqual([state]);
        }
    });

    test('a stored track goes green and a failed one goes red', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();

        // Away from the button: hover has its own colour, and Playwright leaves
        // the pointer where it last clicked.
        await page.mouse.move(0, 0);

        await button.evaluate((el) => { el.dataset.state = 'stored'; });
        expect((await settled(page, button)).colour).toBe('rgb(52, 211, 153)');

        await button.evaluate((el) => { el.dataset.state = 'failed'; });
        expect((await settled(page, button)).colour).toBe('rgb(252, 165, 165)');
    });

    test('a real download ends on the stored glyph', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();

        await button.click();
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        const { icons } = await settled(page, button);

        expect(icons).toEqual(['stored']);
    });
});

/**
 * Download state across a rebuilt list.
 *
 * The offline shell rebuilds rows from the mirror, and a pre-render runs on
 * every tap while the server is answering — so these rows are what the user
 * actually sees for the moment before the server's page lands. They carried no
 * download button at all, and then started at idle once they did, so a
 * downloaded track looked like it had lost its download.
 */
test.describe('download state on rebuilt rows', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1500);
    });

    test('a rebuilt row has a download button', async ({ page }) => {
        await page.evaluate(() => window.soundchexLibrary.preRender(
            document.querySelector('main'), '/app/music',
        ));

        await expect(page.locator('[data-download]:not(#download-toggle)').first()).toBeVisible();
    });

    test('a stored track stays marked through a pre-render', async ({ page }) => {
        const button = page.locator('[data-download]:not(#download-toggle)').first();

        await button.click();
        await expect(button).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        await page.evaluate(() => window.soundchexLibrary.preRender(
            document.querySelector('main'), '/app/music',
        ));

        await expect(page.locator('[data-download]:not(#download-toggle)').first())
            .toHaveAttribute('data-state', 'stored', { timeout: 10000 });
    });
});
