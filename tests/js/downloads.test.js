import { describe, expect, it } from 'vitest';
import { formatBytes } from '../../resources/js/downloads.js';

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
