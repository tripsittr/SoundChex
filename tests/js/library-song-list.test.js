import { describe, expect, it } from 'vitest';
import { songList } from '../../resources/js/library/render.js';

/**
 * What the offline shell builds for a list of songs.
 *
 * The queue used to be stringified onto every row, exactly as the server did
 * it — sixty rows with two play controls each meant 120 copies of the same
 * array in the DOM, on the phone, which is where it costs most. The server
 * page was fixed; this is the same fix on the client, and this is what stops
 * it coming back.
 */
describe('songList', () => {
    const track = (id, title) => ({
        id,
        type: 'music',
        title,
        file_path: `/tmp/${id}.mp3`,
    });

    const items = [track(1, 'One'), track(2, 'Two'), track(3, 'Three')];

    it('returns the queue once rather than per row', () => {
        const { rows, queue } = songList(items);

        expect(rows).toHaveLength(3);
        expect(queue).toHaveLength(3);
        expect(queue.map((entry) => entry.id)).toEqual([1, 2, 3]);
    });

    it('leaves no copy of the queue on any row', () => {
        const { rows } = songList(items);

        for (const row of rows) {
            // Both controls — the artwork and the title.
            const controls = row.querySelectorAll('button');

            expect(controls.length).toBeGreaterThanOrEqual(2);

            for (const control of controls) {
                expect(control.dataset.play).toBeUndefined();
            }
        }
    });

    it('gives every row its position in the queue', () => {
        const { rows } = songList(items);

        // Without an index a row cannot say where in the queue it starts, and
        // every tap would play the first track.
        const indexes = rows.map(
            (row) => row.querySelector('[data-play-index]').dataset.playIndex,
        );

        expect(indexes).toEqual(['0', '1', '2']);
    });

    it('holds an empty list without inventing a queue', () => {
        const { rows, queue } = songList([]);

        expect(rows).toEqual([]);
        expect(queue).toEqual([]);
    });
});
