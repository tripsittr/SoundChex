import * as mirror from './mirror.js';
import * as query from './query.js';
import { fill, poster, songList } from './render.js';

/**
 * Takes over a page when the server cannot be reached.
 *
 * Progressive enhancement rather than a rewrite. Online, Blade renders the
 * page exactly as before and this does nothing — so the online experience
 * cannot regress, which matters because that is the path used every day.
 * Offline, the same screens are rebuilt from the mirror.
 *
 * The alternative — rendering every screen client-side always — means the
 * first paint waits on JavaScript and IndexedDB even when the server is right
 * there and faster. That trade is wrong for a self-hosted app on a home
 * network.
 */

/** Screens this can rebuild, and how. */
const SCREENS = [
    {
        match: (path) => path === '/app/music' || path === '/app/music/',
        render: async (root) => {
            const items = query.sortBy(query.byType(await mirror.all(), 'music'), 'title');

            return renderSongs(root, items, 'Songs');
        },
    },
    {
        match: (path) => path === '/app/albums',
        render: async (root) => {
            const groups = query.albums(await mirror.all());

            return renderAlbums(root, groups);
        },
    },
    {
        match: (path) => path === '/app/artists',
        render: async (root) => {
            const people = query.artists(await mirror.all());

            return renderArtists(root, people);
        },
    },
    {
        match: (path) => /^\/app\/(movie|show|book)$/.test(path),
        render: async (root, path) => {
            const type = path.split('/').pop();
            const items = query.sortBy(query.byType(await mirror.all(), type), 'title');

            return renderGrid(root, items, type);
        },
    },
];

/* --------------------------------------------------------------- chrome --- */

function banner(count) {
    const bar = document.createElement('div');

    bar.className = 'offline-banner';
    bar.setAttribute('role', 'status');
    bar.textContent = count > 0
        ? `Offline — showing ${count} items from this device. Downloads still play.`
        : 'Offline, and nothing has been synced to this device yet.';

    return bar;
}

function heading(text, count) {
    const wrap = document.createElement('div');

    wrap.className = 'mb-4 flex items-baseline justify-between gap-4';
    wrap.append(
        Object.assign(document.createElement('h1'), {
            className: 'text-2xl font-bold text-ink-100 sm:text-3xl',
            textContent: text,
        }),
        Object.assign(document.createElement('p'), {
            className: 'text-sm text-ink-500',
            textContent: String(count),
        }),
    );

    return wrap;
}

function container() {
    const div = document.createElement('div');

    div.className = 'mx-auto max-w-7xl px-4 pb-16 pt-20 sm:px-8';

    return div;
}

/* -------------------------------------------------------------- screens --- */

function renderSongs(root, items, title) {
    const wrap = container();
    const list = document.createElement('ol');

    list.className = 'divide-y divide-base-700/40';

    fill(list, songList(items));
    wrap.append(banner(items.length), heading(title, items.length), list);
    fill(root, [wrap]);

    return items.length;
}

function renderGrid(root, items, label) {
    const wrap = container();
    const grid = document.createElement('div');

    grid.className = 'grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8';

    fill(grid, items.map(poster));
    wrap.append(banner(items.length), heading(label, items.length), grid);
    fill(root, [wrap]);

    return items.length;
}

function renderAlbums(root, groups) {
    const wrap = container();
    const grid = document.createElement('div');

    grid.className = 'grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6';

    fill(grid, groups.map((group) => {
        const link = document.createElement('a');

        link.className = 'group block';
        link.href = `/app/album?artist=${encodeURIComponent(group.artist)}&album=${encodeURIComponent(group.album)}`;

        const frame = document.createElement('div');

        frame.className = 'aspect-square overflow-hidden rounded-lg bg-base-700 shadow-lg shadow-black/30';

        if (group.artwork) {
            const image = document.createElement('img');

            image.src = group.artwork;
            image.alt = '';
            image.loading = 'lazy';
            image.className = 'size-full object-cover';
            frame.append(image);
        } else {
            frame.append(Object.assign(document.createElement('div'), {
                className: 'flex size-full items-center justify-center text-3xl text-ink-600',
                textContent: '♪',
            }));
        }

        link.append(
            frame,
            Object.assign(document.createElement('p'), {
                className: 'mt-2 truncate text-sm font-medium text-ink-100',
                textContent: group.album,
            }),
            Object.assign(document.createElement('p'), {
                className: 'truncate text-xs text-ink-500',
                textContent: `${group.artist} · ${group.track_count}`,
            }),
        );

        return link;
    }));

    wrap.append(banner(groups.length), heading('Albums', groups.length), grid);
    fill(root, [wrap]);

    return groups.length;
}

function renderArtists(root, people) {
    const wrap = container();
    const list = document.createElement('ul');

    list.className = 'grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6';

    fill(list, people.map((person) => {
        const row = document.createElement('li');
        const link = document.createElement('a');

        link.className = 'group block text-center';
        link.href = `/app/artist?name=${encodeURIComponent(person.artist)}`;

        const frame = document.createElement('div');

        frame.className = 'mx-auto aspect-square w-full overflow-hidden rounded-full bg-base-700';

        if (person.artwork) {
            const image = document.createElement('img');

            image.src = person.artwork;
            image.alt = '';
            image.loading = 'lazy';
            image.className = 'size-full object-cover';
            frame.append(image);
        } else {
            frame.append(Object.assign(document.createElement('div'), {
                className: 'flex size-full items-center justify-center text-2xl text-ink-600',
                textContent: (person.artist || '?').charAt(0).toUpperCase(),
            }));
        }

        link.append(
            frame,
            Object.assign(document.createElement('p'), {
                className: 'mt-2 truncate text-sm font-medium text-ink-100',
                textContent: person.artist,
            }),
            Object.assign(document.createElement('p'), {
                className: 'truncate text-xs text-ink-500',
                textContent: `${person.track_count} tracks`,
            }),
        );

        row.append(link);

        return row;
    }));

    wrap.append(banner(people.length), heading('Artists', people.length), list);
    fill(root, [wrap]);

    return people.length;
}

/* ------------------------------------------------------------- takeover --- */

/**
 * Rebuilds the current screen from the mirror.
 *
 * Returns false when this path is not one it knows how to draw, so a caller
 * can leave the browser's own offline page in place rather than replacing it
 * with a blank shell that looks like a bug.
 */
export async function takeOver() {
    const root = document.querySelector('main');

    if (!root) return false;

    const path = window.location.pathname;
    const screen = SCREENS.find((candidate) => candidate.match(path));

    if (!screen) return false;

    try {
        await screen.render(root, path);

        // The play buttons are bound by a delegated listener on document, so
        // rendered rows work without rebinding anything.
        return true;
    } catch {
        return false;
    }
}

/**
 * Whether the server answered.
 *
 * navigator.onLine is not enough on its own: it reports the network interface,
 * not whether this particular server is reachable — which over Tailscale is a
 * different question entirely, since the phone can be on wifi with the tailnet
 * unreachable.
 */
export async function serverReachable(timeout = 4000) {
    if (navigator.onLine === false) return false;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch('/api/v1/me', {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        return response.ok || response.status === 401;
    } catch {
        return false;
    } finally {
        clearTimeout(timer);
    }
}
