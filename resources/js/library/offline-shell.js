import * as mirror from './mirror.js';
import * as query from './query.js';
import { artwork, fill, playerPayload, poster, songList } from './render.js';

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

/**
 * How many rows a rebuilt screen draws.
 *
 * The server paginates to 48; this drew everything in the mirror, which for a
 * real library is over thirteen hundred rows — each with an image, so a single
 * pre-render asked the server for thirteen hundred files. Measured here: 684
 * artwork requests in one minute, and a music page that never finished loading
 * while books and films, with a dozen items between them, were instant.
 *
 * A pre-render is a placeholder shown for a moment before the server's page
 * replaces it. Drawing more than a screenful was never the point.
 */
const MAX_ROWS = 60;

/** Screens this can rebuild, and how. */
const SCREENS = [
    {
        // The home screen, and the landing place when the server is off. It
        // has to be first: without it a launch with nothing reachable showed
        // the generic offline page while the whole catalogue sat unread.
        match: (path) => path === '/app' || path === '/app/',
        render: async (root) => {
            const items = await mirror.all();
            const music = query.sortBy(query.byType(items, 'music'), 'title');

            return renderSongs(root, music.slice(0, MAX_ROWS), 'Your library', music.length);
        },
    },
    {
        match: (path) => path === '/app/music' || path === '/app/music/',
        render: async (root) => {
            const items = query.sortBy(query.byType(await mirror.all(), 'music'), 'title');

            return renderSongs(root, items.slice(0, MAX_ROWS), 'Songs', items.length);
        },
    },
    {
        match: (path) => path === '/app/albums',
        render: async (root) => {
            const groups = query.albums(await mirror.all());

            return renderAlbums(root, groups.slice(0, MAX_ROWS));
        },
    },
    {
        match: (path) => path === '/app/artists',
        render: async (root) => {
            const people = query.artists(await mirror.all());

            return renderArtists(root, people.slice(0, MAX_ROWS));
        },
    },
    {
        match: (path) => /^\/app\/(movie|show|book)$/.test(path),
        render: async (root, path) => {
            const type = path.split('/').pop();
            const items = query.sortBy(query.byType(await mirror.all(), type), 'title');

            return renderGrid(root, items.slice(0, MAX_ROWS), type);
        },
    },
    {
        match: (path) => path === '/app/search',
        render: async (root) => {
            const term = new URLSearchParams(window.location.search).get('q') ?? '';
            const items = query.search(await mirror.all(), term);

            return renderSearch(root, items, term);
        },
    },
    {
        match: (path) => /^\/app\/item\/\d+$/.test(path),
        render: async (root, path) => {
            const id = Number(path.split('/').pop());
            const item = await mirror.find(id);

            if (!item) return 0;

            return renderDetail(root, item);
        },
    },
];

/* --------------------------------------------------------------- chrome --- */

/**
 * A quiet note that this is the device's copy.
 *
 * Deliberately understated. It used to be a coloured banner at the top of
 * every screen, which made the app look like a different, lesser application
 * the moment the server went away — the intent is the same app with less in
 * it, not a fallback mode.
 *
 * Suppressed entirely when the page still has its own chrome: there the nav,
 * tabs and player are all present and the app plainly *is* itself, so
 * announcing otherwise is noise.
 */
function banner(count) {
    const bar = document.createElement('p');

    bar.className = 'offline-note';
    bar.setAttribute('role', 'status');
    bar.textContent = count > 0
        ? 'Showing what is saved on this device.'
        : 'Nothing is saved on this device yet.';

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

    // Clears the fixed header, which is taller than it looks on a notched
    // phone: its inner nav is padded by the safe-area inset, so on a 16 Pro the
    // header runs to ~119px against ~60px on desktop. This was pt-20 (80px) and
    // the first rows sat underneath it — invisible, and unreachable by
    // scrolling, since the page was already at the top.
    //
    // The class matches what the server-rendered music page uses, and the
    // safe-area top-up is applied below for the notch.
    div.className = 'clears-header mx-auto max-w-7xl px-4 pb-16 pt-32 sm:px-8 sm:pt-36';

    return div;
}

/**
 * Whether the page still has the app's own chrome around it.
 *
 * When the server rendered the page, the header, tab bar and player are all
 * present and only the content needs replacing. When the app opened straight
 * into the offline shell there is nothing but a bare <main>, and the note is
 * the only thing saying where the data came from.
 */
function hasChrome() {
    return document.querySelector('header') !== null
        && document.querySelector('.mobile-tabs, .music-subnav') !== null;
}

/* -------------------------------------------------------------- screens --- */

/**
 * The music sub-navigation, rebuilt.
 *
 * The server renders these tabs inside <main>, and rebuilding a screen replaces
 * everything in there — so an offline music screen lost the one control that
 * moves between Songs, Albums, Artists, Genres and Playlists, stranding the
 * user on whichever screen they happened to open.
 *
 * Mirrors x-media.music-nav: same classes, same order, same wire:navigate, so
 * the two are indistinguishable and a tab behaves identically whichever drew
 * it.
 *
 * Every tab is a real link. Genres and Playlists were rendered as disabled
 * spans on the grounds that the mirror cannot fill them, which made them dead
 * on a working online page — the pre-render runs while the server is answering,
 * so a tab disabled for being offline was disabled the rest of the time too.
 * The server's page replaces this within a moment and can fill them itself.
 */
const MUSIC_TABS = [
    { label: 'Songs', href: '/app/music' },
    { label: 'Albums', href: '/app/albums' },
    { label: 'Artists', href: '/app/artists' },
    { label: 'Genres', href: '/app/genres' },
    { label: 'Playlists', href: '/app/playlists' },
];

function musicSubnav(path) {
    const wrap = document.createElement('div');
    const nav = document.createElement('nav');

    wrap.className = 'music-subnav-wrap';
    nav.className = 'music-subnav';
    nav.setAttribute('aria-label', 'Music sections');

    MUSIC_TABS.forEach((tab) => {
        const active = path === tab.href || path === `${tab.href}/`;

        const link = document.createElement('a');

        link.href = tab.href;
        // Without this every tab is a full page load, which tears down the
        // audio element and stops whatever is playing. The server's own tabs
        // carry it; these have to as well or the two behave differently.
        link.setAttribute('wire:navigate', '');
        link.className = `music-subnav__tab ${active ? 'is-active' : ''}`.trim();
        link.textContent = tab.label;

        if (active) link.setAttribute('aria-current', 'page');

        nav.append(link);
    });

    // Shuffle and Download all live beside the Songs heading now, not here.

    wrap.append(nav);

    return wrap;
}

/**
 * Marks rebuilt rows whose track is already on the device.
 *
 * Rows are built fresh from the mirror and start at data-state="idle", so
 * without this a downloaded track shows an empty download icon after every
 * pre-render — which reads as the download having been lost.
 *
 * Fired rather than imported: this module is loaded by the offline bundle,
 * which has no access to the download layer, and a hard import would pull
 * IndexedDB code into a shell that may never use it.
 */
function repaintDownloadStates() {
    document.dispatchEvent(new CustomEvent('soundchex:repaint-downloads'));
}

/**
 * What is on this device, when the catalogue is not.
 *
 * Downloads live in their own store, so a device can hold music without holding
 * a catalogue — after a fresh install, or when a sync has never completed. That
 * is exactly when someone reaches for what they downloaded, and being told
 * nothing is saved is both wrong and the least useful thing to say.
 */
async function renderDownloadsOnly(root) {
    let stored = [];

    try {
        const { list } = await import('../downloads.js');

        stored = await list();
    } catch {
        return false;
    }

    if (stored.length === 0) return false;

    const items = stored.map((entry) => ({
        id: entry.id,
        title: entry.title ?? 'Untitled',
        subtitle: entry.type === 'music' ? 'Downloaded' : entry.type,
        type: entry.type ?? 'music',
        artwork: null,
        meta: {},
    }));

    renderSongs(root, items, 'On this device', items.length);

    return items.length;
}

/** Whether a rebuilt screen is one of the music ones. */
function isMusicPath(path) {
    return /^\/app\/(music|albums|artists|genres|playlists)\/?$/.test(path);
}

/**
 * Puts the music tabs back above a rebuilt screen.
 *
 * Applied here rather than inside each render function so a screen added later
 * cannot forget it — every music path goes through one place. Inserted into the
 * screen's own container so it inherits the header clearance rather than
 * sitting above it.
 */
function restoreMusicSubnav(root, path) {
    if (!isMusicPath(path)) return;
    if (root.querySelector('.music-subnav')) return;

    const screen = root.querySelector('.clears-header') ?? root;
    const heading = screen.firstElementChild;

    screen.insertBefore(musicSubnav(path), heading);
}

function renderSongs(root, items, title, total = null) {
    const wrap = container();
    const list = document.createElement('ol');

    list.className = 'divide-y divide-base-700/40';

    fill(list, songList(items));
    if (!hasChrome()) wrap.append(banner(items.length));
    // The real count, not the number drawn: saying "60 songs" to someone with
    // thirteen hundred would be a lie in service of a placeholder.
    wrap.append(heading(title, total ?? items.length), list);
    fill(root, [wrap]);

    return items.length;
}

function renderGrid(root, items, label) {
    const wrap = container();
    const grid = document.createElement('div');

    grid.className = 'grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8';

    fill(grid, items.map(poster));
    if (!hasChrome()) wrap.append(banner(items.length));
    wrap.append(heading(label, items.length), grid);
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

    if (!hasChrome()) wrap.append(banner(groups.length));
    wrap.append(heading('Albums', groups.length), grid);
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

    if (!hasChrome()) wrap.append(banner(people.length));
    wrap.append(heading('Artists', people.length), list);
    fill(root, [wrap]);

    return people.length;
}

/**
 * Search results, with an honest note about what cannot be searched.
 *
 * Dialogue and page text are not mirrored — 2,879 cues and 1,346 pages are a
 * different order of data from the catalogue. Saying so is the point: results
 * that are quietly narrower than usual look like a library that has lost
 * things.
 */
function renderSearch(root, items, term) {
    const wrap = container();

    if (!hasChrome()) wrap.append(banner(items.length));
    wrap.append(heading(term ? `Results for “${term}”` : 'Search', items.length));

    const note = document.createElement('p');

    note.className = 'mb-4 text-xs text-ink-500';
    note.textContent = 'Offline, this searches titles, artists and authors. '
        + 'Searching inside subtitles and books needs a connection.';
    wrap.append(note);

    if (items.length === 0) {
        wrap.append(Object.assign(document.createElement('p'), {
            className: 'py-16 text-center text-ink-500',
            textContent: term.length < 2
                ? 'Type at least two characters.'
                : 'Nothing on this device matches that.',
        }));
    } else {
        const list = document.createElement('ol');

        list.className = 'divide-y divide-base-700/40';
        fill(list, songList(items.filter((item) => item.type === 'music')));

        const others = items.filter((item) => item.type !== 'music');

        if (others.length > 0) {
            const grid = document.createElement('div');

            grid.className = 'mt-6 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6';
            fill(grid, others.map(poster));
            wrap.append(list, grid);
        } else {
            wrap.append(list);
        }
    }

    fill(root, [wrap]);

    return items.length;
}

/**
 * One item.
 *
 * Deliberately sparse next to the server's detail page: that one shows
 * subtitles, related items and metadata history, none of which is mirrored.
 * Showing the fields that exist beats showing empty sections that imply
 * something failed to load.
 */
function renderDetail(root, item) {
    const wrap = container();

    if (!hasChrome()) wrap.append(banner(1));

    const header = document.createElement('div');

    header.className = 'flex flex-col gap-6 sm:flex-row sm:items-end';

    const frame = document.createElement('div');

    frame.className = 'mx-auto aspect-square w-44 shrink-0 overflow-hidden rounded-lg bg-base-700 sm:mx-0 sm:w-52';
    frame.append(artwork(item, 'size-full object-cover'));

    const side = document.createElement('div');

    side.className = 'min-w-0 flex-1 text-center sm:text-left';
    side.append(
        Object.assign(document.createElement('h1'), {
            className: 'text-2xl font-bold text-ink-100 sm:text-4xl',
            textContent: item.title ?? '',
        }),
        Object.assign(document.createElement('p'), {
            className: 'mt-2 text-sm text-ink-400',
            textContent: item.subtitle ?? '',
        }),
    );

    if (item.playable) {
        const play = document.createElement('button');

        play.type = 'button';
        play.className = 'mt-5 inline-flex items-center gap-2 rounded-full bg-accent px-6 py-2.5 text-sm font-semibold text-white';
        play.dataset.play = JSON.stringify([playerPayload(item)]);
        play.textContent = 'Play';
        side.append(play);
    }

    header.append(frame, side);
    wrap.append(header);
    fill(root, [wrap]);

    return 1;
}

/* ------------------------------------------------------------- takeover --- */

/**
 * Draws a screen from the mirror *before* the server answers.
 *
 * Distinct from takeOver(), which rescues a page that failed. This one runs
 * while a navigation is still in flight: over a relay a page costs ~700ms, and
 * the same screen can be drawn from the device in single-digit milliseconds.
 * The server's version replaces it when it lands.
 *
 * Only for a link the app is about to follow, so the URL is passed in rather
 * than read from location — the navigation has not happened yet.
 */
export async function preRender(root, path) {
    const screen = SCREENS.find((candidate) => candidate.match(path));

    if (!screen) return false;

    // Nothing mirrored, nothing to draw.
    //
    // This rendered regardless, so on a device that had not synced yet every
    // link tap replaced the page with "Offline, and nothing has been synced to
    // this device yet" — while the server was up and about to answer. A
    // pre-render is an optimisation; with nothing to show it has to decline
    // rather than blank a working page.
    if (await mirror.count() === 0) return false;

    try {
        await screen.render(root, path);
        restoreMusicSubnav(root, path);
        repaintDownloadStates();

        return true;
    } catch {
        return false;
    }
}

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

    // What was actually asked for, which the service worker passes in — the
    // address bar says /offline.html by the time this runs, so reading it
    // rebuilt the home screen whatever page the user had tapped.
    const wanted = typeof window.__soundchexWanted === 'string'
        ? window.__soundchexWanted.split('?')[0]
        : null;

    const path = wanted ?? window.location.pathname;
    const screen = SCREENS.find((candidate) => candidate.match(path));

    if (!screen) return false;

    // Downloads are worth showing when the catalogue is not there.
    //
    // The mirror and the downloads are separate stores, and this only ever
    // consulted the mirror — so a device holding music it had deliberately
    // downloaded was told nothing was saved on it, which is the one moment that
    // music exists for. Only when the mirror is genuinely empty: a synced
    // library renders its own screens, which say more than a list of files.
    if (await mirror.count() === 0) {
        return renderDownloadsOnly(root);
    }

    try {
        await screen.render(root, path);
        restoreMusicSubnav(root, path);
        repaintDownloadStates();

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
