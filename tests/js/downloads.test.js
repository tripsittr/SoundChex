import { describe, expect, it } from 'vitest';
import { checkSpace, formatBytes } from '../../resources/js/downloads.js';

/**
 * formatBytes labels the storage warnings shown before a download starts. A
 * wrong unit here reads as "this 4 GB film is 4 MB" and talks someone into
 * filling their phone.
 */
describe('formatBytes', () => {
    it('shows megabytes below a gigabyte', () => {
        expect(formatBytes(5 * 1048576)).toBe('5 MB');
        expect(formatBytes(700 * 1048576)).toBe('700 MB');
    });

    it('switches to gigabytes at 1024 MB', () => {
        expect(formatBytes(1024 * 1048576)).toBe('1.0 GB');
        expect(formatBytes(4.5 * 1024 * 1048576)).toBe('4.5 GB');
    });

    it('does not label a gigabyte-scale file in megabytes', () => {
        // The failure that matters: a large film must never read as a small
        // number, whatever rounding does.
        expect(formatBytes(8 * 1024 * 1048576)).toContain('GB');
    });

    it('handles zero and missing sizes without producing NaN', () => {
        // Size comes from a Content-Length header that is sometimes absent.
        for (const value of [0, null, undefined, NaN]) {
            expect(formatBytes(value)).toBe('0 MB');
        }
    });

    it('rounds sub-megabyte files to a whole number', () => {
        expect(formatBytes(1)).toBe('0 MB');
        expect(formatBytes(600 * 1024)).toBe('1 MB');
    });
});

/**
 * The gate in front of every download.
 *
 * It used to answer an unknown quota with `fits: true`, so a platform that
 * reports nothing — which is the platform this feature is for — downloaded
 * without a word and filled the device. "I cannot tell" and "yes" are
 * different answers, and this is where that is enforced.
 */
describe('checkSpace', () => {
    const GB = 1024 * 1024 * 1024;
    const MB = 1024 * 1024;

    /** Stands in for navigator.storage, which jsdom does not provide. */
    const withQuota = (quota, usage) => {
        globalThis.navigator ??= {};

        Object.defineProperty(globalThis.navigator, 'storage', {
            configurable: true,
            value: quota === null
                ? undefined
                : { estimate: async () => ({ quota, usage }) },
        });
    };

    it('refuses when there is not enough room', async () => {
        withQuota(64 * GB, 60 * GB);

        const space = await checkSpace(10 * GB);

        expect(space.known).toBe(true);
        expect(space.fits).toBe(false);
    });

    it('allows a download that leaves the device usable', async () => {
        withQuota(128 * GB, 8 * GB);

        const space = await checkSpace(10 * GB);

        expect(space.fits).toBe(true);
        expect(space.tight).toBe(false);
    });

    it('flags a download that fits but leaves little', async () => {
        // Fits, and leaves ~80 MB of a 1 GB quota — under a tenth. Worth
        // asking about rather than refusing: it is the user's device.
        withQuota(GB, 0);

        const space = await checkSpace(GB - 80 * MB);

        expect(space.fits).toBe(true);
        expect(space.tight).toBe(true);
    });

    it('asks rather than assumes when no figure is available', async () => {
        // The regression this exists for. `fits: true` here meant iOS, which
        // reports nothing useful, downloaded 40 GB without a prompt.
        withQuota(null);

        const space = await checkSpace(10 * GB);

        expect(space.known).toBe(false);
        expect(space.fits).toBe(false);
    });

    it('keeps a reserve rather than filling the quota exactly', async () => {
        // 100 MB left in the quota, 90 MB wanted. It arithmetically fits and
        // would leave 10 MB, which is where a write fails partway.
        withQuota(GB, GB - 100 * MB);

        const space = await checkSpace(90 * MB);

        expect(space.fits).toBe(false);
    });

    it('does not refuse a normal download against a 1 GB quota', async () => {
        // The regression a device-scale reserve caused: the e2e browser
        // reports a 1 GB quota with 0.98 GB free, and a 2 GB margin refused
        // every download there — including "download all" in a suite that had
        // been passing. A quota is not a disk.
        withQuota(GB, 8 * MB);

        const space = await checkSpace(200 * MB);

        expect(space.fits).toBe(true);
    });

    it('reports what would be left, for the message', async () => {
        withQuota(128 * GB, 28 * GB);

        const space = await checkSpace(10 * GB);

        expect(space.after).toBe(90 * GB);
    });
});
