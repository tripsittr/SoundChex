import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Collecting what happened while the app was closed.
 *
 * Pull rather than push: reaching a phone in someone's pocket needs Apple's
 * service and its own infrastructure. What arrives here is what a push
 * implementation would send, so this is the same payload either way.
 *
 * Nothing is shown unless the profile asked for it. The OS prompt is a one-off
 * per install, and firing notifications at someone who never turned them on is
 * how an app gets muted for good.
 */
test.describe('notifications', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
    });

    test('the collector is available to the app', async ({ page }) => {
        expect(await page.evaluate(() => typeof window.soundchexNotifications?.collect))
            .toBe('function');
    });

    test('nothing is collected when the profile has not asked for it', async ({ page }) => {
        // Off by default, and this must be honoured before any request is made.
        const result = await page.evaluate(() => window.soundchexNotifications.collect());

        expect(result.shown).toBe(0);
    });

    test('the preferences are rendered into the page', async ({ page }) => {
        // Read from the page rather than fetched: a request per launch to learn
        // something that rarely changes is a request wasted.
        const prefs = await page.evaluate(() => {
            const raw = document.body.dataset.notificationPrefs;

            return raw ? JSON.parse(raw) : null;
        });

        expect(prefs, 'preferences are present').not.toBeNull();
        expect(prefs).toHaveProperty('enabled');
    });

    test('the endpoint answers with a cursor', async ({ page }) => {
        // The API is token-authenticated, not cookie-authenticated: a device
        // holds a bearer token so it can sync while the session is irrelevant.
        // The app mints one from the session at /app/device-token.
        const payload = await page.evaluate(async () => {
            const { token } = await fetch('/app/device-token', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            }).then((r) => r.json());

            return fetch('/api/v1/notifications', {
                headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            }).then((r) => r.json());
        });

        expect(payload).toHaveProperty('items');
        expect(payload).toHaveProperty('cursor');
    });
});
