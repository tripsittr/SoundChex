import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * A device report should carry what is new, not everything since the tab
 * opened.
 *
 * The buffer was never trimmed after a successful send, so every report
 * repeated the whole thing: 2,306 stored events across 125 rows, 140 of them
 * distinct — 94% repeats. One incident arriving three times reads as three
 * incidents to anyone watching the table, which is exactly how it was found.
 */
describe('diagnostics buffer', () => {
    const store = new Map();

    beforeEach(() => {
        store.clear();
        vi.resetModules();

        vi.stubGlobal('sessionStorage', {
            getItem: (k) => store.get(k) ?? null,
            setItem: (k, v) => store.set(k, v),
            removeItem: (k) => store.delete(k),
        });

        vi.stubGlobal('localStorage', {
            getItem: (k) => store.get(k) ?? null,
            setItem: (k, v) => store.set(k, v),
            removeItem: (k) => store.delete(k),
        });

        vi.stubGlobal('navigator', { userAgent: 'test', onLine: true });
        vi.stubGlobal('crypto', { randomUUID: () => '00000000-0000-4000-8000-000000000000' });
        vi.stubGlobal('document', { title: 'test' });
        vi.stubGlobal('window', { location: { origin: 'https://example.test' } });
    });

    const buffered = () => JSON.parse(store.get('soundchex.diagnostics') ?? '[]');

    it('drops the events it has sent', async () => {
        store.set('soundchex.diagnostics', JSON.stringify([
            { kind: 'page-load', at: 832 },
            { kind: 'playback:failed', at: 7878 },
        ]));

        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true })));

        const { sendReport } = await import('../../resources/js/diagnostics.js');

        expect((await sendReport()).sent).toBe(true);
        expect(buffered()).toEqual([]);
    });

    it('keeps everything when the send fails', async () => {
        // A dropped report must not also lose the evidence it was carrying.
        const events = [{ kind: 'playback:failed', at: 7878 }];

        store.set('soundchex.diagnostics', JSON.stringify(events));

        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })));

        const { sendReport } = await import('../../resources/js/diagnostics.js');

        expect((await sendReport()).sent).toBe(false);
        expect(buffered()).toEqual(events);
    });
});
