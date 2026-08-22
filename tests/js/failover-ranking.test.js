import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fastest, isRelayed, isStable } from '../../resources/js/library/failover.js';

/**
 * Which address the app talks to, and why it is not simply the quickest.
 *
 * Three tiers, and each exists because of a specific failure:
 *
 *   - A relay is last whatever it measures. The Funnel hostname is a `.ts.net`
 *     name, so the stability test says yes to it, and stability outranked
 *     speed — which meant an 843ms relay beat a 92ms address on the same Wi-Fi
 *     at every launch.
 *   - A stable address beats an unstable one at comparable speed, because a LAN
 *     address that is momentarily quickest stops existing when the server
 *     changes networks.
 *   - Speed decides the rest.
 */
describe('route ranking', () => {
    const LAN = 'http://192.168.1.93:8000';
    const TAILNET = 'http://100.106.62.120:8000';
    const FUNNEL = 'https://macbookair.tail7e590c.ts.net';

    // Each address answers, at the speed the real ones were measured at. The
    // point of the ranking is what it does when everything works — a route
    // that fails is not the interesting case.
    const TIMINGS = { [LAN]: 92, [TAILNET]: 37, [FUNNEL]: 843 };

    beforeEach(() => {
        vi.stubGlobal('fetch', (url) => {
            const origin = Object.keys(TIMINGS).find((o) => String(url).startsWith(o));

            if (!origin) return Promise.reject(new Error('unknown origin'));

            return new Promise((resolve) => {
                setTimeout(() => resolve({
                    ok: true,
                    json: () => Promise.resolve({ app: 'soundchex' }),
                }), 0);
            });
        });
    });

    it('knows a relay from a direct route', () => {
        expect(isRelayed(FUNNEL)).toBe(true);
        expect(isRelayed(TAILNET)).toBe(false);
        expect(isRelayed(LAN)).toBe(false);
    });

    it('counts a tailnet address as stable and a LAN address as not', () => {
        expect(isStable(TAILNET)).toBe(true);
        expect(isStable(FUNNEL)).toBe(true);
        expect(isStable(LAN)).toBe(false);
    });

    it('prefers a slow direct route to a fast relay', async () => {
        // The relay measuring well is exactly the case that used to win.
        const winner = await fastest([FUNNEL, LAN], 50);

        expect(winner?.origin).toBe(LAN);
    });

    it('prefers a stable direct route to a LAN one', async () => {
        const winner = await fastest([LAN, TAILNET], 50);

        expect(winner?.origin).toBe(TAILNET);
    });

    it('falls back to the relay when it is the only thing answering', async () => {
        // Away from home with no tailnet, the relay is the whole reason the
        // app works at all. Ranked last is not the same as excluded.
        const winner = await fastest([FUNNEL], 50);

        expect(winner?.origin).toBe(FUNNEL);
    });
});
