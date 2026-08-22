import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
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
