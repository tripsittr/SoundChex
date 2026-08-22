import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Surviving iOS closing the database.
 *
 * Reported from the phone on 21 August 2026: one "UnknownError: An internal
 * error was encountered in the Indexed Database server", then 27 consecutive
 * "InvalidStateError: The database connection is closing" — every transaction
 * afterwards failing against a handle nobody had noticed was dead.
 *
 * The connection was cached in a module-level promise and never cleared, so
 * once iOS closed it — backgrounding, memory pressure, storage eviction — the
 * only recovery was restarting the app.
 *
 * Asserted against the source rather than a fake IDB: a fake convincing enough
 * to exercise this would be reimplementing IndexedDB's own semantics, and the
 * bug is not in the transaction logic — it is in whether the handle is ever
 * released. That is visible, and stays visible, in the source.
 */
describe.each([
    ['downloads', 'resources/js/downloads.js'],
    ['library mirror', 'resources/js/library/mirror.js'],
    // Missed the first time, and kept producing the same unhandled rejection
    // from a third database nobody had looked at.
    ['write queue', 'resources/js/library/write-queue.js'],
])('%s database', (_name, path) => {
    const source = readFileSync(resolve(__dirname, '../..', path), 'utf8');

    it('drops the cached connection when the database closes', () => {
        // Without this the handle outlives the connection and every later
        // transaction throws for the rest of the session.
        expect(source).toMatch(/onclose\s*=/);
        expect(source).toMatch(/dbPromise\s*=\s*null/);
    });

    it('does not block another tab upgrading the schema', () => {
        expect(source).toMatch(/onversionchange\s*=/);
        expect(source).toMatch(/db\.close\(\)/);
    });

    it('retries a transaction that failed on a dead connection', () => {
        // onclose only fires once the page is told. A connection that dies
        // between opening and using it raises InvalidStateError instead, and
        // that has to be recovered from too.
        expect(source).toContain('InvalidStateError');
        expect(source).toMatch(/retrying/);
    });

    it('clears the cache when opening fails', () => {
        // A rejected open promise cached forever is the same bug in a
        // different coat: every later call awaits a failure that already
        // happened.
        expect(source).toMatch(/onerror\s*=\s*\(\)\s*=>\s*\{[\s\S]{0,200}dbPromise\s*=\s*null/);
    });
});

/**
 * Every IndexedDB database, so a fourth cannot be added without this treatment.
 *
 * The fix was applied to two databases and the bug went on being reported,
 * because there was a third. Enumerating them here means the next one is
 * caught by a failing test rather than by reading a device report.
 */
describe('every database', () => {
    it('is covered by the recovery tests above', () => {
        const roots = ['resources/js', 'resources/js/library'];
        const found = [];

        for (const dir of roots) {
            for (const file of readdirSync(resolve(__dirname, '../..', dir))) {
                if (! file.endsWith('.js')) continue;

                const path = `${dir}/${file}`;
                const source = readFileSync(resolve(__dirname, '../..', path), 'utf8');

                // A one-shot open that never caches a handle cannot go stale.
                if (source.includes('indexedDB.open') && source.includes('dbPromise')) {
                    found.push(path);
                }
            }
        }

        expect(found.sort()).toEqual([
            'resources/js/downloads.js',
            'resources/js/library/mirror.js',
            'resources/js/library/write-queue.js',
        ]);
    });
});
