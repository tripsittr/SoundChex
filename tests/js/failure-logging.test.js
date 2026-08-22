import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Which failures say what happened.
 *
 * The app runs on a phone that is not in the room, so a failure nobody wrote
 * down is a failure reported as "it didn't work". These are the paths where
 * that cost real time to diagnose — each one previously returned or showed a
 * message and recorded nothing.
 *
 * Asserted against the source rather than by exercising each path: several
 * need a dead network, a corrupt IndexedDB or a backgrounded phone to reach,
 * and the failure guarded against is the reporting call being dropped.
 */
describe('failure logging', () => {
    const read = (path) => readFileSync(resolve(__dirname, '../..', path), 'utf8');

    it.each([
        ['a track that will not play', 'resources/js/player.js', 'playback:failed'],
        ['a sync that could not reach the server', 'resources/js/library/sync.js', 'sync:failed'],
        ['a book that will not open', 'resources/js/reader.js', 'reader:archive:failed'],
        ['a download that failed', 'resources/js/downloads.js', 'download:'],
        ['the library list behind "Download all"', 'resources/js/download-button.js', 'download:library:failed'],
        ['adding to a playlist', 'resources/js/playlists.js', 'playlist:add:failed'],
        ['shuffling the library', 'resources/js/playlists.js', 'shuffle:failed'],
        ['a downloaded film that could not be read', 'resources/js/watch.js', 'watch:local-source:failed'],
        ['learning the server addresses', 'resources/js/library/failover.js', 'addresses:failed'],
        ['the offline rescue render', 'resources/js/library/offline-shell.js', 'takeover:failed'],
        ['painting a page ahead of navigation', 'resources/js/library/offline-shell.js', 'prerender:failed'],
    ])('records %s', (_what, path, kind) => {
        expect(read(path)).toContain(kind);
    });

    it('sends any failure kind without it being listed', () => {
        // The download logging was written first and reported nothing, because
        // WORTH_REPORTING was a list and its kind was not on it. Reporting by
        // suffix means a new failure kind cannot be forgotten twice.
        const diagnostics = read('resources/js/diagnostics.js');

        expect(diagnostics).toMatch(/endsWith\(':failed'\)/);
        expect(diagnostics).toMatch(/endsWith\(':error'\)/);
    });

    it('never lets reporting break the thing it is reporting on', () => {
        // A diagnostics failure that broke a download would be worse than the
        // silence it was added to fix.
        const log = read('resources/js/log.js');

        expect(log).toMatch(/try\s*\{[\s\S]*?soundchexDiagnostics[\s\S]*?\}\s*catch/);
    });
});
