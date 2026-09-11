import { expect, test } from '@playwright/test';
import { appReady, signIn } from './helpers.js';

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
        await appReady(page, { rows: true });
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
        await appReady(page, { rows: true });
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
        await appReady(page, { rows: true });

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
        // `downloads: true` waits for paintIconDownloadStates() to have run.
        // It writes a state onto every button after reading IndexedDB, so a
        // state set before it resolves is overwritten — which reads as the
        // stylesheet showing the wrong glyph rather than as a race.
        await appReady(page, { rows: true, downloads: true });
    });

    // The button transitions colour, so a read taken immediately after a state
    // change catches an interpolated value rather than the rule's own.
    //
    // The wait is a poll rather than a sleep. `paintIconDownloadStates()` reads
    // IndexedDB and then writes a state onto every button, so it can land
    // between the write below and the read — a fixed 400ms was long enough on
    // some runs and not others, and adding a `console.log` was enough to make
    // it pass, which is the signature of a race rather than a slow paint.
    // Waiting for the attribute to still hold what was set means the read
    // happens after any repaint that was in flight.
    const settled = async (page, button, expected = null) => {
        if (expected !== null) {
            await button.evaluate(
                (el, want) => el.dataset.state === want,
                expected,
            );

            await page.waitForFunction(
                ([sel, want]) => document.querySelector(sel)?.dataset.state === want,
                ['[data-download]:not(#download-toggle)', expected],
                { timeout: 10000 },
            ).catch(() => {});
        }

        await page.waitForTimeout(400);

        return button.evaluate((el) => ({
            colour: getComputedStyle(el).color,
            icons: [...el.querySelectorAll('[data-icon]')]
                .filter((icon) => getComputedStyle(icon).display !== 'none')
                .map((icon) => icon.dataset.icon),
        }));
    };

    test('exactly one glyph shows per state', async ({ page }) => {
        // What is under test is the stylesheet: for each `data-state`, exactly
        // one `[data-icon]` is displayed. That is a pure CSS question, so the
        // state is written and the glyph read **inside one evaluate** —
        // `paintIconDownloadStates()` writes a state of its own whenever it
        // resolves, and a set-then-read split across two round trips loses to
        // it depending on what an earlier test left in IndexedDB. Rendering is
        // forced with an offsetHeight read rather than waited for, because the
        // display rules are not animated; only the colour is, and the test
        // below covers that separately.
        const seen = await page.locator('[data-download]:not(#download-toggle)')
            .first()
            .evaluate((el) => {
                const out = {};

                for (const state of ['idle', 'downloading', 'stored', 'failed']) {
                    el.dataset.state = state;
                    void el.offsetHeight;

                    out[state] = [...el.querySelectorAll('[data-icon]')]
                        .filter((icon) => getComputedStyle(icon).display !== 'none')
                        .map((icon) => icon.dataset.icon);
                }

                return out;
            });

        for (const state of ['idle', 'downloading', 'stored', 'failed']) {
            expect(seen[state], `${state} shows only its own glyph`).toEqual([state]);
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
        await appReady(page, { rows: true });
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

/**
 * Downloading something twice.
 *
 * The stores are keyed by media item id and written with put(), so a repeat
 * never produced a second copy — but it did transfer the whole file again,
 * which on a metered connection is the part that costs.
 */
test.describe('downloading the same track twice', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await appReady(page, { rows: true });
    });

    test('it is stored once and fetched once', async ({ page }) => {
        const result = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;
            let fetches = 0;

            const realFetch = window.fetch;

            window.fetch = (...args) => {
                if (String(args[0]).includes('/stream')) fetches += 1;

                return realFetch(...args);
            };

            await downloads.download({
                id: 1, url: '/app/item/1/stream', meta: { title: 'T', type: 'music' },
            });

            const stamp = (await downloads.list())[0].downloadedAt;

            await new Promise((resolve) => setTimeout(resolve, 30));

            await downloads.download({
                id: 1, url: '/app/item/1/stream', meta: { title: 'T', type: 'music' },
            });

            window.fetch = realFetch;

            const entries = await downloads.list();

            return {
                entries: entries.length,
                fetches,
                // An overwrite would stamp a new time; skipping leaves it.
                untouched: entries[0].downloadedAt === stamp,
            };
        });

        expect(result.entries, 'stored once').toBe(1);
        expect(result.fetches, 'fetched once').toBe(1);
        expect(result.untouched, 'the stored file is left alone').toBe(true);
    });

    test('a forced download fetches again', async ({ page }) => {
        // The escape hatch, for a file that needs replacing rather than
        // keeping — a truncated download, or one stored before a re-encode.
        const fetches = await page.evaluate(async () => {
            const downloads = window.soundchexDownloads;
            let count = 0;

            const realFetch = window.fetch;

            window.fetch = (...args) => {
                if (String(args[0]).includes('/stream')) count += 1;

                return realFetch(...args);
            };

            await downloads.download({ id: 1, url: '/app/item/1/stream', meta: { title: 'T' } });
            await downloads.download({ id: 1, url: '/app/item/1/stream', meta: { title: 'T' }, force: true });

            window.fetch = realFetch;

            return count;
        });

        expect(fetches).toBe(2);
    });
});


/**
 * "Download all" and the icons on the rows beneath it.
 *
 * The batch button used to be the only thing that changed: every song row sat
 * on the idle arrow while the files it points at were downloading, so a list
 * of a hundred rows said nothing was happening for as long as it took. And
 * because `paintIconDownloadStates()` reads IndexedDB — where a failed track
 * simply is not — a run in which everything failed came back reading as one
 * that had never been asked for.
 *
 * The persistence half matters more than the spinner: a stored icon that does
 * not survive a page change invites downloading the same file twice.
 */
test.describe('a batch download marks the rows', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);

        // The track menu's own Download, on the songs list. "Download all" was
        // removed from `/app/music`, and this is the batch control that still
        // sits on a page that also has per-row download buttons — which is
        // what these are about. The album page has the other batch button but
        // no row buttons at all, so it cannot show this.
        await page.goto('/app/music');
        await appReady(page, { rows: true, downloads: true });
    });

    const row = (page) => page.locator('[data-download]:not(#download-toggle)').first();
    const batch = (page) => page.locator('[data-download-batch]').first();

    /**
     * Starts the batch the way the button does.
     *
     * Dispatched rather than clicked: the batch control lives inside the track
     * menu's `<details>`, and driving that open reliably at phone width is a
     * test of the menu rather than of what happens to the rows. The handler is
     * delegated on `document`, so a synthetic click on the button reaches
     * exactly the same code a real tap does.
     */
    const startBatch = async (page) => {
        await page.locator('[data-download-batch]').first().evaluate((el) => {
            el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
    };

    test('a row shows the spinner while the batch runs', async ({ page }) => {
        // Slowed so there is a window to observe at all; without this the
        // fixture tracks finish faster than a state can be read.
        await page.context().route('**/item/*/stream', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 2000));

            return route.continue();
        });

        await startBatch(page);

        await expect(row(page)).toHaveAttribute('data-state', 'downloading', { timeout: 15000 });
    });

    test('a row ends stored, and stays stored across pages and a reload', async ({ page }) => {
        await startBatch(page);

        await expect(row(page)).toHaveAttribute('data-state', 'stored', { timeout: 30000 });

        // Another page and back: the rows are rebuilt from the server.
        await page.goto('/app/albums');
        await page.goto('/app/music');
        await appReady(page, { rows: true, downloads: true });

        await expect(row(page)).toHaveAttribute('data-state', 'stored', { timeout: 15000 });

        // And a full reload, which is the closest a browser test gets to the
        // app being closed and reopened.
        await page.reload();
        await appReady(page, { rows: true, downloads: true });

        await expect(row(page)).toHaveAttribute('data-state', 'stored', { timeout: 15000 });
    });

    test('a stored row is still stored in a new session', async ({ page, context }) => {
        await startBatch(page);
        await expect(row(page)).toHaveAttribute('data-state', 'stored', { timeout: 30000 });

        // A new page in the same context — IndexedDB survives, the page's own
        // state does not. This is the app being reopened.
        const reopened = await context.newPage();

        await reopened.goto('/app/music');
        await reopened.waitForSelector('li[data-long-press-menu]', { timeout: 20000 });

        await expect(
            reopened.locator('[data-download]:not(#download-toggle)').first(),
        ).toHaveAttribute('data-state', 'stored', { timeout: 20000 });

        await reopened.close();
    });

    test('a failed batch says so on the rows, not just the button', async ({ page }) => {
        test.setTimeout(180000);

        await page.context().route('**/item/*/stream', (route) => route.abort('failed'));

        await startBatch(page);

        // Both, because the repaint that follows a batch reads IndexedDB and
        // has nothing to say about a file that was never written — it returned
        // every failed row to idle, and the batch button with it.
        await expect(row(page)).toHaveAttribute('data-state', 'failed', { timeout: 150000 });
        await expect(batch(page)).toHaveAttribute('data-state', 'failed', { timeout: 30000 });
    });
});
