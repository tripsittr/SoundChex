import { describe, expect, it } from 'vitest';

import { currentLine, parseLrc } from '../../resources/js/lyrics.js';

/**
 * The LRC parser behind the player's lyrics panel (S-301).
 *
 * It deliberately mirrors `LRCLine.parse` on iOS: the same file should behave
 * the same on both, and a second interpretation of a timestamp format is a
 * second set of bugs. These are the cases that distinguish the two readings.
 */
describe('parseLrc', () => {
    it('reads mm:ss with and without a fraction', () => {
        const lines = parseLrc('[00:12.50]Words\n[01:05]More');

        expect(lines.map((l) => l.time)).toEqual([12.5, 65]);
    });

    it('honours the digit count of the fraction', () => {
        // "5" is a tenth and "05" a hundredth — reading one as the other puts
        // a line up to a second out, which is visible on a synced panel.
        const lines = parseLrc('[00:10.5]Tenth\n[00:20.05]Hundredth\n[00:30.500]Milli');

        expect(lines.map((l) => l.time)).toEqual([10.5, 20.05, 30.5]);
    });

    it('expands a line carrying several timestamps', () => {
        // A chorus is written once and timed several times.
        const lines = parseLrc('[00:30.00][01:30.00][02:30.00]Chorus');

        expect(lines).toHaveLength(3);
        expect(lines.every((l) => l.text === 'Chorus')).toBe(true);
        expect(lines.map((l) => l.time)).toEqual([30, 90, 150]);
    });

    it('sorts by time regardless of file order', () => {
        const lines = parseLrc('[00:30.00]Later\n[00:05.00]Earlier');

        expect(lines.map((l) => l.text)).toEqual(['Earlier', 'Later']);
    });

    it('takes the words after the last timestamp', () => {
        expect(parseLrc('[00:01.00][00:02.00]  Hello  ')[0].text).toBe('Hello');
    });

    it('keeps an empty line rather than dropping it', () => {
        // An empty LRC line is a musical gap; dropping it closes up the
        // spacing and the highlight jumps early.
        const lines = parseLrc('[00:10.00]\n[00:20.00]Words');

        expect(lines).toHaveLength(2);
        expect(lines[0].text).toBe('');
    });

    it('ignores lines with no timestamp', () => {
        // LRC files carry metadata headers like [ar:Artist].
        expect(parseLrc('[ar:Someone]\n[00:10.00]Words')).toHaveLength(1);
    });

    it('returns nothing for empty or untimed input', () => {
        // Which is what tells the panel to fall back to plain words.
        expect(parseLrc(null)).toEqual([]);
        expect(parseLrc('')).toEqual([]);
        expect(parseLrc('Just some words\nwith no timing')).toEqual([]);
    });

    it('does not leak state between calls', () => {
        // The tag pattern is a module-level /g regex, and a shared lastIndex
        // would make every other call miss its first match.
        const once = parseLrc('[00:10.00]A\n[00:20.00]B');
        const twice = parseLrc('[00:10.00]A\n[00:20.00]B');

        expect(twice).toEqual(once);
    });
});

describe('currentLine', () => {
    const lines = parseLrc('[00:10.00]One\n[00:20.00]Two\n[00:30.00]Three');

    it('is the last line whose time has passed', () => {
        expect(currentLine(lines, 25)).toBe(1);
    });

    it('holds the previous line through a gap', () => {
        // Not the nearest line: a long instrumental should keep the last sung
        // line lit rather than clearing the panel.
        expect(currentLine(lines, 29.9)).toBe(1);
    });

    it('is -1 before the first line', () => {
        expect(currentLine(lines, 0)).toBe(-1);
    });

    it('stays on the last line past the end', () => {
        expect(currentLine(lines, 9999)).toBe(2);
    });

    it('lights a line exactly on its timestamp', () => {
        expect(currentLine(lines, 20)).toBe(1);
    });

    it('is -1 when there are no lines', () => {
        expect(currentLine([], 42)).toBe(-1);
    });
});
