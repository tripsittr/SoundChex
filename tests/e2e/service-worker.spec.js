import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Evicting assets from a previous build.
 *
 * The worker's cache key was a hardcoded 'v1', so the activate handler that
 * deletes old caches never had a differing key to find and nothing was ever
 * evicted. Assets are content-hashed, which usually makes that harmless — but
 * the cache is served stale-while-revalidate, so a stylesheet from an older
 * build kept being handed out alongside freshly rendered HTML. A page
 * referencing new class names got a sheet that did not define them, and the
 * music header lost the padding holding it below the status bar.
 *
 * The version is now stamped from the build manifest, so a changed asset
 * changes the key and the old cache goes.
 */
test.describe('service worker cache versioning', () => {
    test('the cache key is stamped from the build, not hardcoded', async ({ page }) => {
        await signIn(page);

        const source = await page.evaluate(() => fetch('/sw.js').then((r) => r.text()));
        const version = source.match(/const VERSION = '([^']*)'/)?.[1];

        expect(version, 'the worker declares a version').toBeTruthy();

        // The literal that shipped the bug. Anything still on it is unstamped,
        // which means the build step stopped running.
        expect(version).not.toBe('v1');
    });

    test('the cache key follows the built assets, so a new build evicts the old', async ({ page }) => {
        await signIn(page);

        // The bug was not that eviction failed — it was that the key never
        // changed, so eviction had nothing to act on. Planting an arbitrary old
        // key proves only that activate works; it passes even when the version
        // is hardcoded. What matters is that the key is derived from the build
        // manifest, so shipping different assets yields a different cache.
        const [version, manifest, swSource] = await page.evaluate(async () => {
            const source = await fetch('/sw.js').then((r) => r.text());

            return [
                source.match(/const VERSION = '([^']*)'/)?.[1],
                await fetch('/build/manifest.json').then((r) => r.text()),
                source,
            ];
        });

        // The worker's own source is part of the stamp too, not just the
        // manifest it serves: a change to caching strategy leaves the manifest
        // identical, and the version would otherwise stay put while the old
        // worker kept running with its old caches. Recomputed here the same way
        // `scripts/embed-offline-shell.mjs` builds it, with the VERSION line
        // itself removed — it cannot be an input to its own hash.
        const digest = await page.evaluate(async ([manifestText, source]) => {
            const bytes = new TextEncoder().encode(manifestText + source);
            const hash = await crypto.subtle.digest('SHA-1', bytes);

            return [...new Uint8Array(hash)].map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, 12);
        }, [
            JSON.stringify(JSON.parse(manifest)),
            swSource.replace(/const VERSION = '[^']*';/, ''),
        ]);

        expect(version, 'the cache key is the manifest and worker digest').toBe(digest);
    });

    test('a cache from an older build is deleted on activation', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        // Wait for the worker to control the page, so the activate handler has
        // already run once and cannot be credited with the eviction below.
        await page.evaluate(() => navigator.serviceWorker.ready);

        // Plant what a previous build would have left: a cache under an older
        // key, holding a stylesheet that no longer matches the current one.
        const planted = await page.evaluate(async () => {
            const cache = await caches.open('soundchex-assets-oldbuild');
            await cache.put('/build/stale.css', new Response('.gone{}', {
                headers: { 'Content-Type': 'text/css' },
            }));

            return (await caches.keys()).includes('soundchex-assets-oldbuild');
        });

        expect(planted, 'the stale cache was planted').toBe(true);

        // Unregister and re-register, which forces a fresh install/activate
        // cycle. Calling update() alone does not: the worker's bytes are
        // unchanged, so the browser keeps the existing one and never re-runs
        // activate — the eviction would appear broken when it is not.
        await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.getRegistration();
            await registration?.unregister();
        });

        await page.reload();
        await page.evaluate(() => navigator.serviceWorker.ready);

        await expect.poll(
            () => page.evaluate(() => caches.keys()),
            { message: 'the old cache is evicted', timeout: 15000 },
        ).not.toContain('soundchex-assets-oldbuild');

        // The current build's cache survives: this evicts by age, not by
        // clearing everything, or every launch would refetch the whole bundle.
        const remaining = await page.evaluate(() => caches.keys());
        expect(remaining.some((key) => key.startsWith('soundchex-assets-'))).toBe(true);
    });
    test('a stale cached asset never wins while the server is reachable', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.evaluate(() => navigator.serviceWorker.ready);

        const asset = await page.evaluate(async () => {
            const manifest = await fetch('/build/manifest.json').then((r) => r.json());

            return '/build/' + Object.values(manifest).find((entry) => entry.file?.endsWith('.css')).file;
        });

        // Poison the cache the way a previous build's worker did: real URL,
        // wrong bytes. Served stale-first, this is what reached the phone —
        // fresh HTML styled by a stylesheet that no longer matched it.
        await page.evaluate(async (url) => {
            const cache = await caches.open((await caches.keys()).find((k) => k.startsWith('soundchex-assets-')));

            await cache.put(url, new Response('/* poisoned */', {
                headers: { 'Content-Type': 'text/css' },
            }));
        }, asset);

        const served = await page.evaluate((url) => fetch(url).then((r) => r.text()), asset);

        expect(served, 'the network answer is served, not the poisoned cache').not.toContain('poisoned');
    });
});
