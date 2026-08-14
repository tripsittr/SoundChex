import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Bulk upload, end to end.
 *
 * This broke in three separate places at once, none of which produced a
 * server-side error:
 *
 *   1. `acceptedFileTypes()` was given dotted extensions, which drives a
 *      `mimetypes:` rule that rejects them.
 *   2. The page had no `mount()`, so `data.files` did not exist and Livewire
 *      refused to bind the input.
 *   3. FilePond checks the browser MIME type client-side and refused
 *      everything with "File of invalid type" before sending a byte.
 *
 * Each was invisible from the logs, so these tests drive the real widget.
 *
 * Uploaded files are removed afterwards. They are tiny fakes with no decodable
 * audio, and leaving them in the shared fixture library broke ten unrelated
 * playback tests — the player picks the first track it finds and got one that
 * cannot be decoded.
 */
test.describe('bulk upload', () => {
    // Serial: these add rows to the fixture library, and a parallel playback
    // test picking one up mid-run is exactly the interference being avoided.
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(async ({ page }) => {
        await signIn(page, 'Owner');
        await page.goto('/admin/bulk-upload');
        await page.waitForTimeout(1200);
    });

    test('a media file is accepted rather than refused by the widget', async ({ page }) => {
        await attach(page, 'accepted.mp3');

        const item = page.locator('.filepond--item').first();

        await expect(item).toBeVisible();
        await expect(item).not.toContainText('invalid type');
    });

    test('an uploaded file reaches the library', async ({ page }) => {
        await attach(page, 'reaches-library.mp3');

        await expect
            .poll(async () => (await page.locator('.filepond--item').first().innerText()).includes('complete'), {
                timeout: 20000,
            })
            .toBe(true);

        await page.getByRole('button', { name: /add to library/i }).first().click();

        // "Nothing to upload" is the failure this whole area produced: the
        // widget accepted a file and the form never saw it.
        await expect(page.locator('body')).not.toContainText('Nothing to upload');
        await expect(page.locator('body')).toContainText(/uploaded/i);
    });

    test('the progress indicator shows while bytes are moving', async ({ page }) => {
        // Polled from inside the page: the bar removes itself shortly after
        // finishing, so checking once afterwards would always miss it.
        await page.evaluate(() => {
            window.__sawBar = false;
            window.__barTimer = setInterval(() => {
                if (document.getElementById('sc-upload-progress')) window.__sawBar = true;
            }, 60);
        });

        await attach(page, 'progress.mp3', 5_000_000);
        await page.waitForTimeout(6000);
        await page.evaluate(() => clearInterval(window.__barTimer));

        expect(await page.evaluate(() => window.__sawBar)).toBe(true);
    });

    test('the indicator is patched in before any upload starts', async ({ page }) => {
        // Filament's FilePond sends its own XHR and dispatches no DOM events,
        // so the indicator hooks XMLHttpRequest. If that patch is missing,
        // nothing is shown no matter what happens on the wire.
        expect(await page.evaluate(() => window.soundchexUploadBound)).toBe(true);
    });
});

async function attach(page, name, size = 8192) {
    await page.locator('input[type="file"]').first().setInputFiles({
        name,
        mimeType: 'audio/mpeg',
        buffer: Buffer.from('ID3'.padEnd(size, '\0')),
    });
}
