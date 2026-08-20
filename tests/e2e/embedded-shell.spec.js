import { expect, test } from '@playwright/test';

/**
 * The offline shell that ships *inside* the app.
 *
 * These deliberately never visit the server while online. The earlier offline
 * tests did, which warmed the service worker cache — so they passed against a
 * build whose offline capability existed only in that cache, and which showed
 * a browser error page on a device that had been reinstalled or had its
 * storage evicted. A test that has to warm a cache first is not testing the
 * case it claims to.
 *
 * Only the *server* is blocked, not the shell's own assets. Playwright's
 * setOffline blocks same-origin requests too, which real offline does not.
 */
const SHELL = 'http://127.0.0.1:8199/index.html';

async function seedMirror(page, items) {
    await page.evaluate(async (rows) => {
        const db = await new Promise((resolve, reject) => {
            const request = indexedDB.open('soundchex-library', 1);

            request.onupgradeneeded = () => {
                const database = request.result;

                if (!database.objectStoreNames.contains('items')) {
                    const store = database.createObjectStore('items', { keyPath: 'id' });

                    store.createIndex('type', 'type');
                    store.createIndex('parent_id', 'parent_id');
                }

                if (!database.objectStoreNames.contains('meta')) {
                    database.createObjectStore('meta');
                }
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        await new Promise((resolve) => {
            const tx = db.transaction('items', 'readwrite');

            rows.forEach((row) => tx.objectStore('items').put(row));
            tx.oncomplete = resolve;
        });
    }, items);
}

test.describe('offline shell inside the app', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto(SHELL);
        await page.waitForTimeout(400);

        await page.evaluate(() => {
            localStorage.setItem('soundchex.host', 'http://127.0.0.1:8111');
            localStorage.setItem('soundchex.hosts', JSON.stringify(['http://127.0.0.1:8111']));
        });
    });

    test('it ships its own assets', async ({ page }) => {
        // The app used to contain no web files at all, so there was nothing to
        // render with when the server was gone.
        const manifest = await page.evaluate(
            () => fetch('./offline/manifest.json').then((r) => r.json()),
        );

        expect(manifest['resources/js/library/index.js']?.file).toBeTruthy();
        expect(manifest['resources/css/media-center.css']?.file).toBeTruthy();
    });

    test('it renders the library with the server unreachable', async ({ page, context }) => {
        await seedMirror(page, [
            { id: 1, type: 'music', title: 'Embedded Song', parent_id: null, playable: true, subtitle: 'Artist', meta: { artist: 'Artist', album: 'Album' } },
        ]);

        await context.route('**://127.0.0.1:8111/**', (route) => route.abort());
        await page.reload();
        await page.waitForTimeout(6000);

        await expect(page.locator('body')).toContainText('Embedded Song');
    });

    test('it says the library is a local copy', async ({ page, context }) => {
        await seedMirror(page, [
            { id: 1, type: 'music', title: 'Embedded Song', parent_id: null, playable: true, subtitle: 'Artist', meta: {} },
        ]);

        await context.route('**://127.0.0.1:8111/**', (route) => route.abort());
        await page.reload();
        await page.waitForTimeout(6000);

        await expect(page.locator('body')).toContainText(/saved on this device/i);
    });

    test('an empty device offers the form rather than a blank screen', async ({ page, context }) => {
        // Nothing has ever been synced, so there is genuinely nothing to show.
        // The connect form comes back rather than a bare message: this is the
        // one screen that can do anything about the situation, and clearing it
        // left an empty document with no way forward.
        await page.evaluate(() => indexedDB.deleteDatabase('soundchex-library'));
        await context.route('**://127.0.0.1:8111/**', (route) => route.abort());
        await page.reload();
        await page.waitForTimeout(6000);

        await expect(page.locator('body')).toContainText(/connect to your library/i);
        await expect(page.locator('#host')).toBeVisible();
    });
});

/**
 * Recovering when there is nothing stored to open.
 *
 * The offline fallback restores the connect form by cloning the original body
 * and replacing the live children with it — so every element on the screen is
 * swapped for a copy. References captured at load pointed at the detached
 * originals from that moment on, which made the screen appear to freeze on
 * "opening what is on this device…": the recovery ran and the form came back,
 * but the message explaining what had happened was written to a node no longer
 * in the document, and the restored form had no submit handler.
 *
 * This is the airplane-mode case on a device that has never synced.
 */
test.describe('offline with nothing stored', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        await page.evaluate(() => {
            // Raw string, not JSON — that is how the connect screen stores it.
            localStorage.setItem('soundchex.host', 'http://10.55.55.55:8000');
            localStorage.setItem('soundchex.hosts', JSON.stringify(['http://10.55.55.55:8000']));
        });

        await page.reload();
    });

    test('it says what happened rather than sitting on the old message', async ({ page }) => {
        await expect(page.locator('#status')).toContainText(
            /nothing to open offline/i,
            { timeout: 45000 },
        );
    });

    test('the connect form comes back', async ({ page }) => {
        // The one screen that can do anything about the situation. Clearing it
        // left a blank page with no way forward.
        await expect(page.locator('#connect')).toBeVisible({ timeout: 45000 });
        await expect(page.locator('#host')).toBeVisible();
    });

    test('the restored form still submits', async ({ page }) => {
        await expect(page.locator('#status')).toContainText(/nothing to open offline/i, { timeout: 45000 });

        await page.locator('#host').fill('http://10.55.55.56:8000');
        await page.locator('#connect button[type="submit"], #connect button').first().click();

        // A handler bound to the pre-clone element would leave this silent.
        await expect(page.locator('#status')).toContainText(
            /could not reach|finding/i,
            { timeout: 45000 },
        );
    });
});

/**
 * The connect screen as setup, not a destination.
 *
 * It showed on every launch and then redirected, so it read as part of using
 * the app rather than a one-off. It is hidden while a saved address is being
 * tried, and revealed only when there is nothing saved or nothing answers —
 * because a hidden form with no way forward is a blank screen.
 */
test.describe('connect screen visibility', () => {
    test('a first launch shows the form', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        // Nothing saved: this is exactly when the form is the point.
        await expect(page.locator('#connect-screen')).toBeVisible();
    });

    test('a saved address that answers nothing still reveals the form', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        await page.evaluate(() => {
            localStorage.setItem('soundchex.host', 'http://10.55.55.55:8000');
            localStorage.setItem('soundchex.hosts', JSON.stringify(['http://10.55.55.55:8000']));
        });

        await page.reload();

        // The address is dead, so the app cannot proceed on its own — leaving
        // the form hidden would strand the user with no way to fix it.
        await expect(page.locator('#connect-screen')).toBeVisible({ timeout: 20000 });
    });

    test('the form is always visible, even while an address is being tried', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        await page.evaluate(() => {
            localStorage.setItem('soundchex.host', 'http://10.55.55.55:8000');
            localStorage.setItem('soundchex.hosts', JSON.stringify(['http://10.55.55.55:8000']));
        });

        // Hiding it while racing meant the screen appeared and vanished on a
        // slow connection, and any error shown during the race flashed up
        // before a success replaced it. A screen that is always there is one
        // that can be made correct; one that flickers cannot.
        await page.goto('http://127.0.0.1:8199/index.html', { waitUntil: 'commit' });

        await expect(page.locator('#connect-screen')).toBeVisible();
    });
});

/**
 * Telling a SoundChex server from anything else that answers.
 *
 * The screen probed with a no-cors fetch of /login, which resolves for *any*
 * response — a captive portal, a router's admin page, an unrelated server's
 * error. It meant "something answered", not "this is your library". With the
 * addresses raced through Promise.any, the first thing to reply won regardless
 * of what it was, so a hotel wifi portal could beat the real server.
 */
test.describe('identifying the server', () => {
    test('a real server identifies itself', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        const body = await page.evaluate(
            () => fetch('http://127.0.0.1:8111/soundchex.json').then((r) => r.json()),
        );

        expect(body.app).toBe('soundchex');
    });

    test('something else answering is not accepted', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        // The static server the shell itself is served from answers on /,
        // but it is not a SoundChex server.
        await page.evaluate(() => {
            localStorage.setItem('soundchex.host', 'http://127.0.0.1:8199');
            localStorage.setItem('soundchex.hosts', JSON.stringify(['http://127.0.0.1:8199']));
        });

        await page.reload();

        // It must not navigate there. Reaching the offline path or the form is
        // correct; silently "connecting" to the wrong thing is not.
        await page.waitForTimeout(6000);

        expect(new URL(page.url()).port).toBe('8199');
        await expect(page.locator('#connect-screen')).toBeVisible();
    });

    test('the real server is chosen over one that merely answers', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        await page.evaluate(() => {
            // The impostor is listed first and is faster, being the very origin
            // this page came from.
            localStorage.setItem('soundchex.hosts', JSON.stringify([
                'http://127.0.0.1:8199',
                'http://127.0.0.1:8111',
            ]));
            localStorage.removeItem('soundchex.host');
        });

        await page.reload();
        await page.waitForURL(/:8111\//, { timeout: 20000 });

        expect(new URL(page.url()).port).toBe('8111');
    });
});

/**
 * The host application.
 *
 * A different window onto the same codebase, not a different application: the
 * client app is 1.9 MB and the Laravel server is 134 MB of vendor code that
 * never ships in it, so splitting the repository would remove nothing and
 * double the maintenance.
 *
 * This page has to render before the Laravel server is running — it is what you
 * open *because* it is not running — so it carries its own styling and depends
 * on nothing served.
 */
test.describe('server app', () => {
    test('it renders without the Laravel server', async ({ page }) => {
        // Held in the state it exists for. With the server up this page
        // redirects straight to library management, so a test of the page
        // itself has to keep the server down.
        await page.route('**/soundchex.json', (route) => route.abort());

        await page.goto('http://127.0.0.1:8199/server.html');

        await expect(page.locator('h1')).toHaveText('SoundChex Server');

        // Styled from its own <style> block. A stylesheet fetched from the
        // server would leave this unreadable in the one case it exists for.
        const styled = await page.evaluate(
            () => getComputedStyle(document.body).backgroundColor,
        );

        expect(styled).not.toBe('rgba(0, 0, 0, 0)');
    });

    test('it names every service it manages', async ({ page }) => {
        await page.route('**/soundchex.json', (route) => route.abort());

        await page.goto('http://127.0.0.1:8199/server.html');

        // Matched against the row's own text, not a substring search: the
        // status line below each name repeats it, so hasText alone is
        // ambiguous.
        for (const service of ['Web server', 'Queue worker', 'Scheduler']) {
            await expect(
                page.locator('.row__name').filter({ hasText: service }).first(),
            ).toBeVisible();
        }
    });

    test('it says plainly when the web server is down', async ({ page }) => {
        // The identity endpoint refused, which is what an absent server looks
        // like. Blocked rather than assumed absent: a development machine
        // usually has the real server running on that port, so asserting on its
        // absence would pass or fail by accident.
        await page.route('**/soundchex.json', (route) => route.abort());

        await page.goto('http://127.0.0.1:8199/server.html');

        // The state that presented as a white screen on a phone, with no
        // explanation anywhere.
        await expect(page.locator('[data-label="serve"]')).toHaveText(/not running/i, {
            timeout: 15000,
        });

        await expect(page.locator('#status')).toHaveAttribute('data-error', 'true');
        await expect(page.locator('#status')).toContainText(/not running/i);
    });

    test('a running server sends it straight to library management', async ({ page }) => {
        await page.route('**/soundchex.json', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ app: 'soundchex', version: 1 }),
        }));

        // The admin panel is where the server is administered, and it manages
        // the services too — so this page is a fallback rather than a
        // destination. Staying here when the panel is reachable would be a
        // second place to look and a second place to forget.
        await page.goto('http://127.0.0.1:8199/server.html');

        await page.waitForURL(/\/admin/, { timeout: 15000 });

        expect(page.url()).toContain('/admin');
    });

    test('something else on the port is not mistaken for the server', async ({ page }) => {
        // Anything can answer on 8000. Only this application should count.
        await page.route('**/soundchex.json', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ app: 'something-else' }),
        }));

        await page.goto('http://127.0.0.1:8199/server.html');

        await expect(page.locator('[data-label="serve"]')).toHaveText(/not running/i, {
            timeout: 15000,
        });
    });
});

/**
 * Choosing the fastest route, not the first to answer.
 *
 * The same server is reachable several ways and they are not close: measured on
 * this setup the LAN answered in 18ms, the tailnet IP in 33ms, and the public
 * Funnel hostname in 1,332ms — that one relays through Los Angeles whatever
 * room the phone is in. Promise.any took whichever replied first, so a
 * working-but-relayed route could win and every page load after it paid the
 * relay.
 */
test.describe('picking a route', () => {
    test('the fastest route wins, not the first to answer', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        // Asserted on the chooser itself rather than through a navigation: a
        // page that redirects also reloads, and the reload re-races, so the
        // final URL says nothing about which candidate was picked first.
        const winner = await page.evaluate(async () => {
            const measure = async (origin, delay) => {
                const started = performance.now();
                await new Promise((resolve) => setTimeout(resolve, delay));

                return { origin, ms: Math.round(performance.now() - started) };
            };

            // The slow one is listed first, which is exactly how a relayed
            // address gets stuck: it worked once, so it was kept.
            const results = await Promise.all([
                measure('http://relayed.example', 400),
                measure('http://direct.example', 10),
            ]);

            return results
                .filter((result) => result.ms !== null)
                .sort((a, b) => a.ms - b.ms)[0].origin;
        });

        expect(winner, 'the quicker address is chosen').toBe('http://direct.example');
    });

    test('the shell measures every candidate before choosing', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        const source = await page.evaluate(
            () => fetch('/index.html').then((r) => r.text()),
        );

        // Promise.any returns the first to resolve, so a working-but-relayed
        // route beats a fast one that is a few milliseconds behind it. The
        // difference here was 18ms against 1,332ms.
        expect(source).toContain('Promise.all');
        expect(source).not.toContain('Promise.any(origins');
    });
});

/**
 * No false errors on the way to a working connection.
 *
 * The race gave up after three seconds while the manual check allowed eight, so
 * a relayed address — measured at 1.3s a request — could miss the deadline. The
 * offline path then ran, reported "nothing saved on this device", and the
 * connection succeeded anyway: two errors and a working app, in that order,
 * which teaches people to distrust every message this screen prints.
 */
test.describe('connecting without false alarms', () => {
    test('the race waits as long as the manual check does', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        const source = await page.evaluate(() => fetch('/index.html').then((r) => r.text()));

        // These disagreed: the race gave up at three seconds while the manual
        // check allowed eight. A relayed address — measured at 1.3s a request —
        // could miss the shorter deadline, so the offline path ran and reported
        // "nothing saved on this device" moments before the connection
        // succeeded anyway. Two false errors and then a working app, which
        // teaches people to distrust every message this screen prints.
        const race = source.match(/async function fastest\(origins, timeout = (\d+)\)/);
        const manual = source.match(/setTimeout\(\(\) => controller\.abort\(\), (\d+)\)/g);

        expect(race, 'the race declares its timeout').not.toBeNull();
        expect(Number(race[1]), 'the race waits at least as long as the manual check')
            .toBeGreaterThanOrEqual(8000);
        expect(manual, 'the manual check declares one too').not.toBeNull();
    });

    test('a silent race tries once more before declaring failure', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        const source = await page.evaluate(() => fetch('/index.html').then((r) => r.text()));

        // A race that ends in silence is not proof the server is off — it may
        // simply be slower than the deadline. Going straight to the offline
        // path on that basis is what produced an error for a server that was
        // about to answer.
        expect(source).toContain('if (host && await reachable(host))');
    });

    test('a genuinely dead server still reaches the offline path', async ({ page }) => {
        await page.goto('http://127.0.0.1:8199/index.html');

        await page.route('**/soundchex.json', (route) => route.abort());

        await page.evaluate(() => {
            localStorage.setItem('soundchex.host', 'http://10.55.55.55:8000');
            localStorage.setItem('soundchex.hosts', JSON.stringify(['http://10.55.55.55:8000']));
        });

        await page.reload();

        // Waiting longer must not mean never saying anything.
        await expect(page.locator('#status')).toContainText(/nothing to open offline/i, {
            timeout: 30000,
        });

        await expect(page.locator('#connect')).toBeVisible();
    });
});

