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

        await expect(page.locator('body')).toContainText(/offline/i);
    });

    test('an empty device says so rather than showing a blank screen', async ({ page, context }) => {
        // Nothing has ever been synced, so there is genuinely nothing to show.
        // A blank page reads as broken; saying so reads as a state.
        await context.route('**://127.0.0.1:8111/**', (route) => route.abort());
        await page.reload();
        await page.waitForTimeout(6000);

        await expect(page.locator('body')).toContainText(/nothing has been synced|nothing to open/i);
    });
});
