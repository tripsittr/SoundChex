import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Saying what failed, rather than that something did.
 *
 * "Download all" showed "Checking…" and then a bare "Could not reach the
 * library" with nothing recorded on either side — no server log, no device
 * report, no way to tell a slow response from a dead one. On this library the
 * list is ~0.7 MB for 5,500 tracks and takes half a second to build before it
 * is sent, so a slow route looks exactly like a broken one.
 */
test.describe('download-all diagnostics', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.locator('main').waitFor({ timeout: 15000 });
    });

    test('a successful listing is recorded with how long it took', async ({ page }) => {
        await page.evaluate(() => { window.soundchexDiagnostics?.record?.('test:reset', {}); });

        const button = page.locator('[data-download-library]').first();

        if (await button.count() === 0) test.skip();

        await button.click();
        await page.waitForTimeout(2000);

        const events = await page.evaluate(() => window.soundchexDiagnostics.events().map((e) => e.kind));

        expect(events).toContain('download:library-requested');
        expect(events).toContain('download:library-listed');
    });

    test('a failure says which request failed and why', async ({ page }) => {
        // The server is unreachable rather than slow.
        //
        // Reloaded after the route is installed. `beforeEach` has already
        // navigated, and this spec's *first* test downloads the library — so
        // the service worker and the page's own module state are warm, and the
        // listing came back without the route ever being consulted. Landing on
        // a fresh page with the abort already registered is what makes the
        // failure the thing under test rather than a race with a warm cache.
        // Routed on the **context**, not the page. A service worker controls
        // this origin, and a request it makes on the page's behalf never
        // reaches `page.route()` — Playwright's default is
        // `serviceWorkers: 'allow'`, so the listing came back from the worker
        // and the abort was never consulted. `requestfinished` fired for a
        // request the route handler had never seen, which is what made this
        // look like a timing problem rather than an interception one.
        await page.context().route('**/app/downloadable', (route) => route.abort('failed'));
        await page.reload();
        await page.locator('main').waitFor({ timeout: 15000 });

        // Emptied rather than marked. `record('test:reset')` only *appends* an
        // event, so the buffer still held the previous test's successful
        // listing — and `sessionStorage` carries it across tests in the same
        // context. This asserted on someone else's events and the 40-entry cap
        // decided which.
        await page.evaluate(() => sessionStorage.removeItem('soundchex.diagnostics'));

        const button = page.locator('[data-download-library]').first();

        if (await button.count() === 0) test.skip();

        await button.click();

        // Waits for the failure to be recorded rather than assuming 2s is
        // enough — the fetch has a 30s timeout behind it, and a fixed sleep
        // asserts on whatever happens to have landed.
        await page.waitForFunction(
            () => (window.soundchexDiagnostics?.events() ?? [])
                .some((e) => e.kind === 'download:library:failed'),
            null,
            { timeout: 20000 },
        );

        // Filtered to this one kind rather than every :failed event. The
        // original asserted across all of them, so an unrelated sync:failed
        // satisfied "something failed" and then failed the url check — which
        // it did on mobile, where the sync does fail, and not on desktop.
        const failures = await page.evaluate(() => window.soundchexDiagnostics.events()
            .filter((e) => e.kind === 'download:library:failed'));

        expect(failures.length, 'the library listing recorded its own failure').toBeGreaterThan(0);

        // The url is what "Load failed" alone never told anyone.
        expect(JSON.stringify(failures)).toContain('downloadable');

        // And whether the device thought it was online, since navigator.onLine
        // knows about the interface rather than the route.
        expect(JSON.stringify(failures)).toContain('online');
    });

    test('a failure kind is one the reporter sends unprompted', async ({ page }) => {
        // Asserted through the reporter rather than by watching for the POST.
        // Playwright's route interception suppresses a keepalive request
        // entirely — verified: with a route active, even a manual record sends
        // nothing — so a test that intercepts the failing request cannot also
        // observe the report it causes.
        //
        // What this checks is the part that was actually broken: the download
        // logging recorded faithfully and sent nothing, because its kind was
        // not on the report list. Kinds ending in :failed now report by rule,
        // so a new failure kind cannot be forgotten in a second place.
        const posted = [];

        page.on('request', (request) => {
            if (request.url().includes('/api/v1/device-reports')) posted.push(request.url());
        });

        await page.evaluate(() => window.soundchexDiagnostics.record('download:library:failed', {
            url: '/app/downloadable',
        }));

        await expect.poll(() => posted.length, { timeout: 10000 }).toBeGreaterThan(0);
    });

});
