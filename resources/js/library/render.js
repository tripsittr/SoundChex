/**
 * Rendering mirrored items as the same markup Blade produces.
 *
 * The classes here are not arbitrary: they match `poster.blade.php` and
 * `song-row.blade.php` exactly, so a screen rendered from the mirror is
 * visually identical to the server-rendered one. Anything else and the app
 * would look subtly different the moment it went offline, which reads as
 * broken rather than as degraded.
 *
 * Built with DOM methods rather than innerHTML strings. Titles and artist
 * names come from file tags — arbitrary text that has already been through a
 * scanner — and textContent cannot be talked into executing any of it.
 */

/** Type glyphs, matching the Blade fallback for missing artwork. */
const GLYPH = {
    music: '♪',
    movie: '🎬',
    show: '📺',
    book: '📖',
};

const ASPECT = {
    music: 'aspect-square',
    movie: 'aspect-[2/3]',
    show: 'aspect-[2/3]',
    book: 'aspect-[2/3]',
};

function element(tag, className, text = null) {
    const node = document.createElement(tag);

    if (className) node.className = className;
    if (text !== null) node.textContent = text;

    return node;
}

/**
 * Artwork, or a glyph when there is none.
 *
 * A grey box reads as broken; a type glyph reads as deliberate.
 */
function artwork(item, className) {
    if (item.artwork) {
        const image = element('img', className);

        image.src = item.artwork;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';

        return image;
    }

    const fallback = element(
        'div',
        `${className} flex items-center justify-center text-2xl text-ink-600`,
        GLYPH[item.type] ?? '♪',
    );

    return fallback;
}

/**
 * A poster tile, for grids of films, shows and books.
 */
export function poster(item) {
    const link = element('a', 'poster group focus:outline-none');

    link.href = `/app/item/${item.id}`;

    const frame = element('div', `${ASPECT[item.type] ?? 'aspect-square'} w-full`);

    frame.append(artwork(item, 'size-full'));

    const title = element('p', 'poster-title', item.title ?? '');
    const subtitle = element('p', 'poster-subtitle', item.subtitle ?? '');

    link.append(frame, title, subtitle);

    return link;
}

/**
 * A song row, matching song-row.blade.php.
 *
 * The queue is embedded per row exactly as the server does it, so clicking
 * track five leaves the rest of the list queued behind it — the behaviour that
 * makes an album feel like an album.
 */
export function songRow(item, queue, index) {
    const row = element('li', 'group flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-base-800/60');

    row.dataset.longPressMenu = '';

    const play = element('button', 'relative flex size-11 shrink-0 items-center justify-center overflow-hidden rounded bg-base-700');

    play.type = 'button';
    play.dataset.play = JSON.stringify(queue);
    play.dataset.playIndex = String(index);
    play.setAttribute('aria-label', `Play ${item.title ?? ''}`);
    play.append(artwork(item, 'size-full object-cover'));

    const text = element('div', 'min-w-0 flex-1');
    const title = element('a', 'block truncate text-sm text-ink-100 hover:underline', item.title ?? '');

    title.href = `/app/item/${item.id}`;

    text.append(title, element('span', 'block truncate text-xs text-ink-500', item.subtitle ?? ''));

    row.append(play, text);

    if (item.meta?.duration_ms) {
        const seconds = Math.floor(item.meta.duration_ms / 1000);
        const stamp = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

        row.append(element('span', 'hidden w-11 shrink-0 text-right text-xs tabular-nums text-ink-500 sm:block', stamp));
    }

    return row;
}

/**
 * The player payload for a mirrored item.
 *
 * Mirrors MediaItem::playerPayload() on the server. Built here rather than
 * stored, because `src` and `url` are routes — storing them would freeze a
 * host into the device's copy, and the connect screen exists precisely so the
 * host can change.
 */
export function playerPayload(item) {
    return {
        id: item.id,
        title: item.title,
        subtitle: item.subtitle,
        album: item.meta?.album ?? null,
        artwork: item.artwork,
        src: `/app/item/${item.id}/stream`,
        url: `/app/item/${item.id}`,
        type: item.type,
        resumeAt: null,
    };
}

/**
 * Replaces a container's children with rendered items.
 *
 * replaceChildren rather than innerHTML: it drops the old nodes and their
 * listeners in one step, where assigning innerHTML leaves detached handlers
 * behind on anything that held a reference.
 */
export function fill(container, nodes) {
    container.replaceChildren(...nodes);

    return container;
}

/**
 * A whole list of songs, queue and all.
 */
export function songList(items) {
    const queue = items.map(playerPayload);

    return items.map((item, index) => songRow(item, queue, index));
}
