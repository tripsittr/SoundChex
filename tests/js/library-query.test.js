import { describe, expect, it } from 'vitest';
import {
    albumTracks,
    albums,
    artistAlbums,
    artists,
    byType,
    episodesOf,
    paginate,
    playableOffline,
    search,
    seriesOnly,
    sortBy,
} from '../../resources/js/library/query.js';

/**
 * The offline query layer.
 *
 * This is the logic that used to run in `MediaBrowser` on the server. Offline
 * there is nothing to fall back to, so a wrong answer here is not a slow page
 * — it is a library that appears to be missing things.
 */

const track = (id, title, artist, album, extra = {}) => ({
    id,
    type: 'music',
    title,
    parent_id: null,
    playable: true,
    meta: { artist, album, ...extra },
    ...extra.top ?? {},
});

describe('byType', () => {
    it('filters to one type', () => {
        const items = [track(1, 'Song', 'A', 'X'), { id: 2, type: 'movie', title: 'Film' }];

        expect(byType(items, 'music')).toHaveLength(1);
    });

    it('accepts several types at once', () => {
        // Watch shows films and episodes together.
        const items = [
            { id: 1, type: 'movie', title: 'Film' },
            { id: 2, type: 'show', title: 'Episode' },
            track(3, 'Song', 'A', 'X'),
        ];

        expect(byType(items, ['movie', 'show'])).toHaveLength(2);
    });
});

describe('series and episodes', () => {
    it('lists series without their episodes', () => {
        // Otherwise a series appears once as itself and again per episode.
        const items = [
            { id: 1, type: 'show', title: 'The Bear', parent_id: null },
            { id: 2, type: 'show', title: 'S01E01', parent_id: 1 },
        ];

        expect(seriesOnly(items).map((i) => i.id)).toEqual([1]);
    });

    it('orders episodes by season then number', () => {
        const items = [
            { id: 3, parent_id: 1, meta: { season_number: 2, episode_number: 1 } },
            { id: 2, parent_id: 1, meta: { season_number: 1, episode_number: 10 } },
            { id: 1, parent_id: 1, meta: { season_number: 1, episode_number: 2 } },
        ];

        expect(episodesOf(items, 1).map((i) => i.id)).toEqual([1, 2, 3]);
    });
});

describe('search', () => {
    const items = [
        track(1, 'Loner', 'Nilüfer Yanya', 'Miss Universe'),
        track(2, 'Chicago', 'flipturn', 'Heavy Colors'),
        { id: 3, type: 'book', title: 'The Hobbit', meta: { author: 'J.R.R. Tolkien' } },
    ];

    it('matches a title', () => {
        expect(search(items, 'chicago').map((i) => i.id)).toEqual([2]);
    });

    it('matches an artist and an author', () => {
        expect(search(items, 'flipturn')).toHaveLength(1);
        expect(search(items, 'tolkien')).toHaveLength(1);
    });

    it('ignores accents in either direction', () => {
        // Typing "Nilufer" on a phone keyboard should still find "Nilüfer".
        expect(search(items, 'nilufer')).toHaveLength(1);
        expect(search(items, 'Nilüfer')).toHaveLength(1);
    });

    it('returns nothing for a single character', () => {
        // Otherwise the first keystroke matches most of the library.
        expect(search(items, 'a')).toEqual([]);
    });
});

describe('sortBy', () => {
    it('does not reorder the array it was given', () => {
        // It once sorted the mirror's own cached array in place.
        const items = [track(2, 'B', 'A', 'X'), track(1, 'A', 'A', 'X')];
        const before = items.map((i) => i.id);

        sortBy(items, 'title');

        expect(items.map((i) => i.id)).toEqual(before);
    });

    it('sorts titles naturally, not by code point', () => {
        const items = [track(1, 'Track 10', 'A', 'X'), track(2, 'Track 2', 'A', 'X')];

        expect(sortBy(items, 'title').map((i) => i.title)).toEqual(['Track 2', 'Track 10']);
    });

    it('puts an item with no timestamp last when sorting by recent', () => {
        // A null must not sort to the top as if it were the newest thing.
        const items = [
            { id: 1, title: 'Undated' },
            { id: 2, title: 'Dated', updated_at: '2026-01-01T00:00:00+00:00' },
        ];

        expect(sortBy(items, 'recent')[0].id).toBe(2);
    });
});

describe('albums', () => {
    it('groups tracks by artist and album', () => {
        const items = [
            track(1, 'One', 'flipturn', 'Heavy Colors', { track_number: 1 }),
            track(2, 'Two', 'flipturn', 'Heavy Colors', { track_number: 2 }),
        ];

        const result = albums(items);

        expect(result).toHaveLength(1);
        expect(result[0].track_count).toBe(2);
    });

    it('does not merge two albums that share a title', () => {
        // Every "Greatest Hits" in the library would otherwise collapse into
        // one fake album.
        const items = [
            track(1, 'One', 'Artist A', 'Greatest Hits'),
            track(2, 'Two', 'Artist B', 'Greatest Hits'),
        ];

        expect(albums(items)).toHaveLength(2);
    });

    it('orders tracks by disc then number', () => {
        const items = [
            track(1, 'Later', 'A', 'X', { disc_number: 2, track_number: 1 }),
            track(2, 'Earlier', 'A', 'X', { disc_number: 1, track_number: 5 }),
        ];

        expect(albums(items)[0].tracks.map((t) => t.title)).toEqual(['Earlier', 'Later']);
    });

    it('falls back to title when numbering is missing', () => {
        // Most tags in a real library carry an implausible track number, which
        // the API strips — so an album often arrives with none at all.
        const items = [
            track(1, 'Beta', 'A', 'X'),
            track(2, 'Alpha', 'A', 'X'),
        ];

        expect(albums(items)[0].tracks.map((t) => t.title)).toEqual(['Alpha', 'Beta']);
    });

    it('ignores tracks with no album', () => {
        expect(albums([track(1, 'Single', 'A', null)])).toEqual([]);
    });
});

describe('artists', () => {
    it('counts tracks and distinct albums', () => {
        const items = [
            track(1, 'One', 'flipturn', 'Heavy Colors'),
            track(2, 'Two', 'flipturn', 'Heavy Colors'),
            track(3, 'Three', 'flipturn', 'Something Else'),
        ];

        const [artist] = artists(items);

        expect(artist.track_count).toBe(3);
        expect(artist.album_count).toBe(2);
    });
});

describe('paginate', () => {
    const items = Array.from({ length: 10 }, (_, i) => ({ id: i + 1 }));

    it('returns a page and the shape the UI needs', () => {
        const page = paginate(items, 1, 4);

        expect(page.items).toHaveLength(4);
        expect(page.pages).toBe(3);
        expect(page.total).toBe(10);
    });

    it('clamps a page beyond the end rather than returning nothing', () => {
        // A stale link to page 9 should land on the last page, not an empty
        // screen that looks like the library vanished.
        expect(paginate(items, 99, 4).page).toBe(3);
    });

    it('clamps a page below one', () => {
        expect(paginate(items, 0, 4).page).toBe(1);
    });

    it('reports one page when there is nothing', () => {
        expect(paginate([], 1, 4)).toMatchObject({ pages: 1, total: 0, items: [] });
    });
});

describe('playableOffline', () => {
    it('keeps only downloaded, playable items', () => {
        const items = [
            { id: 1, playable: true },
            { id: 2, playable: true },
            // A wishlist row: the server has no bytes for it at all.
            { id: 3, playable: false },
        ];

        expect(playableOffline(items, [1, 3]).map((i) => i.id)).toEqual([1]);
    });

    it('matches ids whether they are strings or numbers', () => {
        // IndexedDB keys come back as numbers; a data attribute is a string.
        expect(playableOffline([{ id: 7, playable: true }], ['7'])).toHaveLength(1);
    });
});

describe('albumTracks', () => {
    it('returns one album\'s tracks in disc/track order', () => {
        const items = [
            track(1, 'Third', 'Garbage', 'Version 2.0', { track_number: 3 }),
            track(2, 'First', 'Garbage', 'Version 2.0', { track_number: 1 }),
            track(3, 'Second', 'Garbage', 'Version 2.0', { track_number: 2 }),
            track(4, 'Elsewhere', 'Garbage', 'Bleed Like Me', { track_number: 1 }),
        ];

        expect(albumTracks(items, 'Garbage', 'Version 2.0').map((t) => t.title))
            .toEqual(['First', 'Second', 'Third']);
    });

    it('matches the artist and album case- and accent-insensitively', () => {
        // The link may carry a different case than the tag; both must resolve.
        const items = [track(1, 'Song', 'Nilüfer Yanya', 'Painless', { track_number: 1 })];

        expect(albumTracks(items, 'nilufer yanya', 'PAINLESS')).toHaveLength(1);
    });

    it('does not mix two albums that share a title under different artists', () => {
        const items = [
            track(1, 'A', 'Artist One', 'Greatest Hits', { track_number: 1 }),
            track(2, 'B', 'Artist Two', 'Greatest Hits', { track_number: 1 }),
        ];

        expect(albumTracks(items, 'Artist One', 'Greatest Hits')).toHaveLength(1);
    });
});

describe('artistAlbums', () => {
    it('groups an artist\'s albums and separates loose singles', () => {
        const items = [
            track(1, 'A1', 'Bush', 'Sixteen Stone', { track_number: 1 }),
            track(2, 'A2', 'Bush', 'Sixteen Stone', { track_number: 2 }),
            track(3, 'Comedown', 'Bush', null, { track_number: null }),
            track(4, 'Other', 'Someone Else', 'Their Album'),
        ];

        const result = artistAlbums(items, 'Bush');

        expect(result.albums).toHaveLength(1);
        expect(result.albums[0].album).toBe('Sixteen Stone');
        expect(result.singles.map((s) => s.title)).toEqual(['Comedown']);
        expect(result.track_count).toBe(3);
    });

    it('returns nothing for an artist not in the library', () => {
        const result = artistAlbums([track(1, 'X', 'Real', 'Album')], 'Nobody');

        expect(result.albums).toHaveLength(0);
        expect(result.singles).toHaveLength(0);
    });
});
