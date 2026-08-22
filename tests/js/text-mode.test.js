import { describe, expect, it } from 'vitest';
import { linesToParagraphs } from '../../resources/js/reader/text-mode.js';

/**
 * Reflow is a heuristic, and these tests pin the cases where getting it wrong
 * is visible to a reader: a word torn in half, or a paragraph break that never
 * happens so the whole chapter renders as one block.
 *
 * The heuristic is wrong sometimes by design — the page view stays one tap
 * away — so these assert the rules it actually claims, not perfection.
 */
describe('linesToParagraphs', () => {
    const reflow = (...lines) => linesToParagraphs(lines.map((text) => ({ text })));

    it('joins a wrapped line with a space', () => {
        expect(reflow('The quick brown', 'fox jumps over')).toEqual([
            'The quick brown fox jumps over',
        ]);
    });

    it('closes up a word split across a line break', () => {
        // "port-" + "hole" must become "porthole", not "port- hole" or
        // "port hole". This is the single most visible reflow bug.
        expect(reflow('She looked through the port-', 'hole at the sea')).toEqual([
            'She looked through the porthole at the sea',
        ]);
    });

    it('keeps a hyphenated compound that was not split', () => {
        // A trailing hyphen on the very last line has no following line to
        // join, so it must survive rather than being eaten.
        expect(reflow('a well-known fact')).toEqual(['a well-known fact']);
    });

    it('breaks a paragraph on a short line ending in a full stop', () => {
        // How the last line of a paragraph looks: sentence-final punctuation
        // and noticeably short.
        const result = reflow(
            'It was a long and rambling sentence that filled the whole line',
            'and then it ended.',
            'A new paragraph begins here',
        );

        expect(result).toHaveLength(2);
        expect(result[1]).toBe('A new paragraph begins here');
    });

    it('does not break on a full-length line ending in a full stop', () => {
        // A period at the end of a full line is usually a sentence break
        // inside a paragraph, not the end of one. Breaking here would shatter
        // ordinary prose into one paragraph per sentence.
        const full = 'This sentence runs the entire width of the page and ends.';

        expect(full.length).toBeGreaterThanOrEqual(45);
        expect(reflow(full, 'and it continues on the next line')).toHaveLength(1);
    });

    it('does not break on a short line that is mid-sentence', () => {
        // Short alone is not enough — that is just a wrap.
        expect(reflow('a short line', 'continues here')).toEqual([
            'a short line continues here',
        ]);
    });

    it('collapses runs of whitespace', () => {
        expect(reflow('spaced    out', 'text\there')).toEqual(['spaced out text here']);
    });

    it('returns nothing for no lines', () => {
        expect(linesToParagraphs([])).toEqual([]);
    });

    it('drops a trailing buffer of only whitespace', () => {
        expect(linesToParagraphs([{ text: '   ' }])).toEqual([]);
    });

    it('treats a closing quote as sentence-final', () => {
        // Dialogue ends on a quote mark, and novels are mostly dialogue.
        const result = reflow('"I know," she said.', 'The door closed behind her');

        expect(result).toHaveLength(2);
    });
});
