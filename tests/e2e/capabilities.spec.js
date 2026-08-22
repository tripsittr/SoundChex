import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * The origins allowed to call into the native shell.
 *
 * Tauri scopes plugin access to what the shell itself serves. The media centre
 * is served by the Laravel server over the network, so without an explicit
 * remote scope every plugin call from those pages is denied — which is how Face
 * ID came to report itself as a browser limitation while running in the app.
 *
 * These are URLPattern strings, not shell globs, and the difference is silent:
 * "http://192.168.*.*:*" parses without error and matches nothing at all, so a
 * capability written that way grants access to no origin while looking correct.
 * URLPattern is a browser API, so this runs in one.
 */
// The mobile capability existed only for the biometric plugin, which has been
// removed: Face ID cannot be reached from a page the webview loaded over the
// network, whatever the ACL says.
const CAPABILITIES = ['default'];

const ORIGINS = [
    // Two different LAN addresses: this machine's changed twice in one day, so
    // the patterns must cover the range rather than a literal address.
    'http://192.168.1.155:8000/app/settings',
    'http://192.168.1.205:8000/app/settings',
    'http://10.0.0.5:8000/app/music',
    'http://100.106.62.120:8000/app/settings',
    'https://macbookair.tail7e590c.ts.net/app/settings',
    'http://localhost:8000/app/settings',
    'http://127.0.0.1:8000/app/music',
];

test.describe('shell capabilities', () => {
    for (const name of CAPABILITIES) {
        test(`${name} reaches every origin the app is served on`, async ({ page }) => {
            const capability = JSON.parse(
                readFileSync(`src-tauri/capabilities/${name}.json`, 'utf8'),
            );

            expect(capability.remote?.urls, 'a remote scope is declared').toBeTruthy();

            await page.goto('/login');

            const unmatched = await page.evaluate(
                ({ patterns, origins }) => origins.filter(
                    (origin) => !patterns.some((pattern) => {
                        try {
                            return new URLPattern(pattern).test(origin);
                        } catch {
                            return false;
                        }
                    }),
                ),
                { patterns: capability.remote.urls, origins: ORIGINS },
            );

            expect(unmatched, 'every origin is covered').toEqual([]);
        });

        test(`${name} declares no pattern that matches nothing`, async ({ page }) => {
            const capability = JSON.parse(
                readFileSync(`src-tauri/capabilities/${name}.json`, 'utf8'),
            );

            await page.goto('/login');

            // A pattern that is valid but matches none of the app's own origins
            // is the failure mode this whole file exists for: it reads as
            // granted access and grants none.
            const dead = await page.evaluate(
                ({ patterns, origins }) => patterns.filter((pattern) => {
                    try {
                        const compiled = new URLPattern(pattern);

                        return !origins.some((origin) => compiled.test(origin));
                    } catch {
                        return true;
                    }
                }),
                { patterns: capability.remote.urls, origins: ORIGINS },
            );

            expect(dead, 'no pattern is dead weight').toEqual([]);
        });
    }
});
