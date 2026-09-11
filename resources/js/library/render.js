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
/** The stand-in for a cover that is missing or would not load. */
function glyphFor(item, className) {
    return element(
        'div',
        `${className} flex items-center justify-center text-2xl text-ink-600`,
        GLYPH[item.type] ?? '♪',
    );
}

export function artwork(item, className) {
    if (item.artwork) {
        const image = element('img', className);

        image.src = item.artwork;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';

        // A cover that fails to load leaves an empty box otherwise — and these
        // rows are drawn from the mirror, which can name a file the server no
        // longer has. The glyph is the same one used when there was never any
        // artwork, so a missing image looks deliberate rather than broken.
        image.addEventListener('error', () => {
            image.replaceWith(glyphFor(item, className));
        }, { once: true });

        return image;
    }

    return glyphFor(item, className);
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
 * Carries only its position. The queue itself is written once onto the list
 * that holds these — see `songList()` — exactly as the server does it, so
 * clicking track five still leaves the rest queued behind it.
 */
export function songRow(item, index) {
    const row = element('li', 'group flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-base-800/60');

    row.dataset.longPressMenu = '';

    const play = element('button', 'relative flex size-11 shrink-0 items-center justify-center overflow-hidden rounded bg-base-700');

    play.type = 'button';
    play.dataset.playIndex = String(index);
    play.setAttribute('aria-label', `Play ${item.title ?? ''}`);
    play.append(artwork(item, 'size-full object-cover'));

    // Tapping the row plays it, matching the server-rendered row. This was a
    // link to the detail page, so the same tap did different things depending
    // on which of the two drew the row.
    const text = element('button', 'min-w-0 flex-1 text-left');

    text.type = 'button';
    text.dataset.playIndex = String(index);
    text.setAttribute('aria-label', `Play ${item.title ?? ''}`);

    text.append(
        element('span', 'block truncate text-sm text-ink-100', item.title ?? ''),
        element('span', 'block truncate text-xs text-ink-500', item.subtitle ?? ''),
    );

    row.append(play, text);

    if (item.meta?.duration_ms) {
        const seconds = Math.floor(item.meta.duration_ms / 1000);
        const stamp = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

        row.append(element('span', 'hidden w-11 shrink-0 text-right text-xs tabular-nums text-ink-500 sm:block', stamp));
    }

    // A rebuilt row had no download button at all, so every pre-render — which
    // is every tap, online — replaced the list with rows whose download icons
    // had vanished, until the server's page landed and put them back. The state
    // itself was never lost; there was nothing to show it on.
    row.append(downloadButton(item));

    return row;
}

/**
 * The per-row download control.
 *
 * Matches the markup in x-media.song-row: same attributes, same four glyphs, so
 * the delegated handler and the stylesheet cover a rebuilt row identically to a
 * server-rendered one.
 */
function downloadButton(item) {
    const button = element(
        'button',
        'download-btn download-btn--icon flex size-8 shrink-0 items-center justify-center rounded text-ink-500 transition hover:bg-base-700 hover:text-ink-100 md:opacity-0 md:group-hover:opacity-100 md:focus:opacity-100',
    );

    button.type = 'button';
    button.dataset.download = String(item.id);
    button.dataset.downloadUrl = `/app/item/${item.id}/stream`;
    button.dataset.downloadTitle = item.title ?? '';
    button.dataset.downloadType = item.type ?? 'music';
    button.dataset.state = 'idle';
    button.setAttribute('aria-label', `Download ${item.title ?? ''}`);

    button.innerHTML = `
        <svg data-icon="idle" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <svg data-icon="downloading" class="size-4 download-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke-opacity="0.25" />
            <path d="M21 12a9 9 0 00-9-9" stroke-linecap="round" />
        </svg>
        <svg data-icon="stored" class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" fill="currentColor" />
            <path d="M7.5 12.4l3 3 6-6.4" fill="none" stroke="var(--color-base-900)" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <svg data-icon="failed" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v4.5M12 16h.01" stroke-linecap="round" />
        </svg>
    `;

    return button;
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
 *
 * Returns the rows *and* the queue to hang on the list element, rather than
 * embedding the queue in every row. Sixty rows with two play controls each was
 * 120 copies of the same array built into the DOM — the phone paying the cost
 * the server page was just relieved of.
 */
export function songList(items) {
    return {
        rows: items.map((item, index) => songRow(item, index)),
        queue: items.map(playerPayload),
    };
}
