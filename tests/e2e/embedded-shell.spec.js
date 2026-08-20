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
            { timeout: 20000 },
        );
    });

    test('the connect form comes back', async ({ page }) => {
        // The one screen that can do anything about the situation. Clearing it
        // left a blank page with no way forward.
        await expect(page.locator('#connect')).toBeVisible({ timeout: 20000 });
        await expect(page.locator('#host')).toBeVisible();
    });

    test('the restored form still submits', async ({ page }) => {
        await expect(page.locator('#status')).toContainText(/nothing to open offline/i, { timeout: 20000 });

        await page.locator('#host').fill('http://10.55.55.56:8000');
        await page.locator('#connect button[type="submit"], #connect button').first().click();

        // A handler bound to the pre-clone element would leave this silent.
        await expect(page.locator('#status')).toContainText(
            /could not reach|finding/i,
            { timeout: 20000 },
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
