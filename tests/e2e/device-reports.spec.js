import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Reading what devices have reported.
 *
 * Reports had been arriving for weeks and the only way to read them was a
 * tinker script — which meant in practice they were read when someone already
 * suspected something, rather than when something happened.
 */
test.describe('device reports', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);

        // Two reports from different devices, so filtering has something to do.
        await page.evaluate(async () => {
            const send = (device, kind, agent) => fetch('/api/v1/device-reports', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    device,
                    name: device === 'aaa11111' ? 'Kitchen Phone' : 'Study Mac',
                    platform: agent,
                    build: 'build123',
                    shell: 'shellabc',
                    app_version: '0.1.0',
                    origin: window.location.origin,
                    events: [{ kind, at: 1, path: '/app', detail: {} }],
                }),
            });

            await send('aaa11111', 'download:library:failed', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X)');
            await send('bbb22222', 'page-load', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');
        });

        await page.goto('/admin/device-reports');
        await page.waitForTimeout(1500);
    });

    test('a report says which device it came from', async ({ page }) => {
        // The whole point: one phone told from another, by a name someone
        // chose rather than a fragment of a user agent.
        await expect(page.locator('section').filter({ hasText: 'Kitchen Phone' }).first()).toBeVisible();
        await expect(page.locator('section').filter({ hasText: 'Study Mac' }).first()).toBeVisible();
    });

    test('it can be narrowed to one device', async ({ page }) => {
        const select = page.locator('select').first();

        await select.selectOption('aaa11111');
        await page.waitForTimeout(1200);

        await expect(page.locator('section').filter({ hasText: 'Kitchen Phone' }).first()).toBeVisible();
        await expect(page.locator('section h3').filter({ hasText: 'Study Mac' })).toHaveCount(0);
    });

    test('it can be narrowed to phones', async ({ page }) => {
        // Derived from the user agent server-side, which is the one thing a
        // user agent is reliable about.
        await page.locator('select').nth(1).selectOption('phone');
        await page.waitForTimeout(1200);

        await expect(page.locator('section').filter({ hasText: 'Kitchen Phone' }).first()).toBeVisible();
        await expect(page.locator('section h3').filter({ hasText: 'Study Mac' })).toHaveCount(0);
    });

    test('it can be narrowed to failures', async ({ page }) => {
        await page.getByRole('checkbox').first().check();
        await page.waitForTimeout(1200);

        // The Mac only sent a page load, so it should drop out.
        await expect(page.locator('section').filter({ hasText: 'Kitchen Phone' }).first()).toBeVisible();
        await expect(page.locator('section h3').filter({ hasText: 'Study Mac' })).toHaveCount(0);
    });

    test('the shell build is shown beside the served one', async ({ page }) => {
        // A device can be current on the served build and months behind on the
        // shell, which is compiled into the binary and cannot update itself.
        await expect(page.getByText(/shell shellabc/).first()).toBeVisible();
    });
});
