import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The wording of the download toasts.
 *
 * The batch label reaches three messages as the subject of a sentence, and the
 * fallback for a set of tracks is the plural "these tracks" — so all three read
 * "these tracks is already on this device", starting a sentence in lower case
 * for good measure.
 *
 * Asserted against the source because these strings are assembled from a helper
 * rather than written out, and the failure is in the assembly.
 */
describe('download message wording', () => {
    const source = readFileSync(
        resolve(__dirname, '../../resources/js/download-button.js'),
        'utf8',
    );

    it('never states a verb without agreeing it with the subject', () => {
        // "${label} is" is the shape that produced "these tracks is".
        expect(source).not.toMatch(/\$\{label\}\s+is\b/);
        expect(source).not.toMatch(/\$\{label\}\s+are\b/);
    });

    it('builds every label sentence through the helper', () => {
        // Three messages take the label as a subject; each must go through
        // subject(), which supplies both the capital and the verb.
        const uses = source.match(/subject\(label\)/g) ?? [];

        expect(uses.length).toBeGreaterThanOrEqual(3);
    });

    it('capitalises a label that opens a sentence', () => {
        expect(source).toMatch(/charAt\(0\)\.toUpperCase\(\)/);
    });

    it('counts what is ahead in the queue, not what is waiting', () => {
        // "1 waiting" read as a count of waiters rather than of items in front.
        expect(source).toMatch(/\$\{position - 1\} ahead/);
        expect(source).not.toMatch(/\$\{position\} waiting/);
    });

    it('pluralises every counted noun', () => {
        // Each of these interpolates a count directly before a noun.
        expect(source).toMatch(/download\$\{resumed === 1 \? '' : 's'\}/);
        expect(source).toMatch(/track\$\{missing\.length === 1 \? '' : 's'\}/);
    });
});
