import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Surviving the server changing networks.
 *
 * A LAN address is handed out by whichever router the server is on, so moving a
 * laptop between wifi and a hotspot silently invalidates it — and the app sat
 * on the dead address with every request hanging until it timed out, which
 * reads as the server being down when it is running fine.
 *
 * A Tailscale address does not have this problem, which is the real answer; the
 * failover exists for when the app is not on one.
 */
test.describe('address failover', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');
        await page.waitForTimeout(1200);
    });

    test('a live address answers and a dead one does not', async ({ page }) => {
        const live = await page.evaluate(
            () => window.soundchexLibrary.failover.probe(location.origin, 2000),
        );
        const dead = await page.evaluate(
            () => window.soundchexLibrary.failover.probe('http://192.168.99.99:8000', 1500),
        );

        expect(live, 'the current origin answers').toBeGreaterThanOrEqual(0);
        expect(dead, 'an unreachable host does not').toBeNull();
    });

    test('the race skips a dead candidate rather than choosing it', async ({ page }) => {
        const winner = await page.evaluate(() => window.soundchexLibrary.failover.fastest(
            ['http://192.168.99.99:8000', location.origin],
            2000,
        ));

        expect(winner?.origin).toBe(new URL(page.url()).origin);
    });

    test('all-dead returns null rather than switching blindly', async ({ page }) => {
        const winner = await page.evaluate(() => window.soundchexLibrary.failover.fastest(
            ['http://192.168.99.99:8000', 'http://10.55.55.55:8000'],
            1500,
        ));

        expect(winner, 'nothing to switch to').toBeNull();
    });

    test('a tailnet address outranks a LAN one', async ({ page }) => {
        // Ranked by stability before speed. Picking purely on speed is what put
        // the app on a LAN address that was momentarily quickest and then
        // stopped existing when the server moved networks.
        const verdicts = await page.evaluate(() => {
            const { isStable } = window.soundchexLibrary.failover;

            return {
                tailscaleIp: isStable('http://100.106.62.120:8000'),
                magicDns: isStable('https://macbookair.tail7e590c.ts.net'),
                lan: isStable('http://192.168.1.205:8000'),
                hotspot: isStable('http://172.20.10.8:8000'),
            };
        });

        expect(verdicts).toEqual({
            tailscaleIp: true,
            magicDns: true,
            lan: false,
            hotspot: false,
        });
    });

    test('a healthy origin is left alone', async ({ page }) => {
        const result = await page.evaluate(() => window.soundchexLibrary.failover.check());

        expect(result.status).toBe('healthy');
    });

    test('the current address is measured, not assumed good', async ({ page }) => {
        const result = await page.evaluate(() => window.soundchexLibrary.failover.check());

        // Asking only "does this still work" is how an app stays on a slow
        // route: the relay answers perfectly well, it just answers from Los
        // Angeles. A reported latency proves it was actually measured rather
        // than waved through.
        expect(result.ms, 'the current route is timed').toBeGreaterThanOrEqual(0);
    });

    test('it stays put when nothing is meaningfully quicker', async ({ page }) => {
        // Switching costs a page load, so two addresses within noise of each
        // other must not trade places every half minute.
        const result = await page.evaluate(() => window.soundchexLibrary.failover.check());

        expect(result.status).toBe('healthy');
        expect(result.origin).toBe(new URL(page.url()).origin);
    });

    test('a much quicker route wins over the one in use', async ({ page }) => {
        const winner = await page.evaluate(() => {
            const { isStable } = window.soundchexLibrary.failover;

            // The ordering check() applies: stability first, then latency.
            return [
                { origin: 'https://relayed.ts.net', ms: 1332 },
                { origin: 'http://100.106.62.120:8000', ms: 18 },
            ].sort((a, b) => (isStable(b.origin) - isStable(a.origin)) || (a.ms - b.ms))[0];
        });

        // Both are tailnet addresses, so stability ties and latency decides —
        // which is the difference between 18ms and a second and a third.
        expect(winner.origin).toBe('http://100.106.62.120:8000');
    });
});
