import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Held, rather than tapped.
 *
 * iOS answers a long press with its own gestures — a text selection on a row,
 * a link preview offering "Open in Safari" on an anchor — and the app's own
 * long-press menu never got a look in. The handler had been there all along;
 * it was competing with the platform and losing.
 */
test.describe('long press on iOS', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.locator('[data-long-press-menu]').first().waitFor({ timeout: 20000 });
    });

    test('the rules iOS needs are actually shipped', async ({ page }) => {
        // Read from the built stylesheet rather than from computed style.
        // -webkit-touch-callout is Safari-only and reads as "" elsewhere, and
        // under mobile emulation user-select reads as "" too — so the element
        // says nothing useful in either project. What can be checked
        // everywhere is that the declarations survived the build: the failure
        // guarded against is the rule being dropped or its selector drifting,
        // not Safari ignoring a property it defines.
        const css = await page.evaluate(async () => {
            const link = [...document.querySelectorAll('link[rel="stylesheet"]')]
                .find((el) => el.href.includes('media-center'));

            if (!link) return '';

            return fetch(link.href).then((response) => response.text());
        });

        expect(css, 'the media-center stylesheet is loaded').not.toBe('');

        // The row, so a held finger opens the menu rather than the magnifier
        // and the copy/share callout.
        expect(css).toMatch(/\[data-long-press-menu\][^}]*touch-callout:\s*none/);
        expect(css).toMatch(/\[data-long-press-menu\][^}]*user-select:\s*none/);

        // Links, so holding one does not offer "Open in Safari" — the
        // destination is this app, and that is an offer to leave it.
        expect(css).toMatch(/#main a[^}]*touch-callout:\s*none/);

        // Except in the reader, where holding text is how a passage is
        // selected to highlight.
        expect(css).toMatch(/#reader-page a[^}]*touch-callout:\s*default/);
    });

});
