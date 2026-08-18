import { formatTime } from './player.js';

/**
 * The full-screen player.
 *
 * The bar is the persistent control; this is where everything that does not
 * fit on it lives — large artwork, a real scrubber, shuffle and repeat as
 * proper targets, and the queue, which previously had no UI at all despite the
 * player supporting it from the start.
 *
 * Like the bar, this rebinds per document rather than capturing elements once:
 * an SPA swap replaces the body, and a captured reference would write into the
 * previous page's detached nodes.
 */
export function bindNowPlayingSheet() {
    const sheet = document.getElementById('np-sheet');

    if (!sheet) return;

    // The bar owns the player singleton. As separate Vite entry points there
    // is no guaranteed execution order, so this can run first — in which case
    // binding is deferred rather than silently skipped, which is what left the
    // sheet inert.
    const player = window.soundchexPlayer;

    if (!player) {
        document.addEventListener('soundchex:player-ready', () => bindNowPlayingSheet(), { once: true });

        return;
    }

    const current = (window.soundchexSheet ??= {});

    const ui = {
        sheet,
        artPane: document.getElementById('np-sheet-art-pane'),
        queuePane: document.getElementById('np-sheet-queue-pane'),
        queueList: document.getElementById('np-sheet-queue'),
        queueToggle: document.getElementById('np-sheet-queue-toggle'),
        close: document.getElementById('np-sheet-close'),
        artwork: document.getElementById('np-sheet-artwork'),
        artworkFallback: document.getElementById('np-sheet-artwork-fallback'),
        title: document.getElementById('np-sheet-title'),
        subtitle: document.getElementById('np-sheet-subtitle'),
        seek: document.getElementById('np-sheet-seek'),
        played: document.getElementById('np-sheet-played'),
        currentTime: document.getElementById('np-sheet-current'),
        duration: document.getElementById('np-sheet-duration'),
        toggle: document.getElementById('np-sheet-toggle'),
        toggleIcon: document.getElementById('np-sheet-toggle-icon'),
        prev: document.getElementById('np-sheet-prev'),
        next: document.getElementById('np-sheet-next'),
        shuffle: document.getElementById('np-sheet-shuffle'),
        repeat: document.getElementById('np-sheet-repeat'),
        repeatOne: document.getElementById('np-sheet-repeat-one'),
        download: document.getElementById('np-sheet-download'),
        playlist: document.getElementById('np-sheet-playlist'),
    };

    current.ui = ui;

    const ICON_PLAY = 'M8 5v14l11-7z';
    const ICON_PAUSE = 'M6 5h4v14H6zM14 5h4v14h-4z';

    /* ------------------------------------------------------------ opening */

    function open() {
        const sheet = current.ui.sheet;

        sheet.classList.remove('translate-y-full');
        sheet.setAttribute('aria-hidden', 'false');
        sheet.removeAttribute('inert');

        // The page behind must not scroll while a full-screen sheet is up.
        document.body.style.overflow = 'hidden';

        paint();
        renderQueue();
    }

    function close() {
        const sheet = current.ui.sheet;

        sheet.classList.add('translate-y-full');
        sheet.setAttribute('aria-hidden', 'true');
        // inert rather than only aria-hidden: an off-screen sheet still holds
        // focusable buttons, and tabbing into an invisible dialog is a trap.
        sheet.setAttribute('inert', '');

        document.body.style.overflow = '';
    }

    const isOpen = () => !current.ui.sheet.classList.contains('translate-y-full');

    /* ---------------------------------------------------------- painting */

    function paint() {
        const item = player.queue[player.index];

        if (!item) return;

        const { ui } = current;

        ui.title.textContent = item.title ?? '';
        ui.subtitle.textContent = item.subtitle ?? '';

        if (item.artwork) {
            ui.artwork.src = item.artwork;
            ui.artwork.hidden = false;
            ui.artworkFallback.hidden = true;
        } else {
            ui.artwork.hidden = true;
            ui.artworkFallback.hidden = false;
        }

        pointActionsAt(item);
        setPlaying(!player.el.paused);
        setModes();
        setTime(player.el.currentTime, player.el.duration);
    }

    function setPlaying(playing) {
        const { ui } = current;

        ui.toggleIcon.setAttribute('d', playing ? ICON_PAUSE : ICON_PLAY);
        ui.toggle.setAttribute('aria-label', playing ? 'Pause' : 'Play');
    }

    function setTime(at, duration) {
        const { ui } = current;
        const fraction = duration ? (at / duration) * 100 : 0;

        ui.played.style.width = `${fraction}%`;
        ui.currentTime.textContent = formatTime(at);
        ui.duration.textContent = formatTime(duration);
    }

    function setModes() {
        const { ui } = current;

        ui.shuffle.classList.toggle('text-accent', player.shuffle);
        ui.shuffle.setAttribute('aria-pressed', String(player.shuffle));

        ui.repeat.classList.toggle('text-accent', player.repeat !== 'off');
        ui.repeat.dataset.mode = player.repeat;
        ui.repeatOne.hidden = player.repeat !== 'one';
    }

    /* ------------------------------------------------------------- queue */

    function renderQueue() {
        const { ui } = current;

        ui.queueList.replaceChildren();

        player.queue.forEach((item, index) => {
            const row = document.createElement('li');
            const button = document.createElement('button');

            button.type = 'button';
            button.className = [
                'flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition',
                index === player.index ? 'bg-base-700' : 'hover:bg-base-800',
            ].join(' ');
            button.dataset.queueIndex = String(index);

            const text = document.createElement('span');
            text.className = 'min-w-0 flex-1';

            const title = document.createElement('span');
            title.className = index === player.index
                ? 'block truncate text-sm font-medium text-accent'
                : 'block truncate text-sm text-ink-200';
            title.textContent = item.title ?? '';

            const subtitle = document.createElement('span');
            subtitle.className = 'block truncate text-xs text-ink-500';
            subtitle.textContent = item.subtitle ?? '';

            text.append(title, subtitle);

            const position = document.createElement('span');
            position.className = 'w-5 shrink-0 text-right text-xs tabular-nums text-ink-600';
            position.textContent = String(index + 1);

            button.append(position, text);
            row.append(button);
            ui.queueList.append(row);
        });
    }

    function showQueue(show) {
        const { ui } = current;

        ui.artPane.hidden = show;
        ui.queuePane.hidden = !show;
        ui.queueToggle.setAttribute('aria-expanded', String(show));
        ui.queueToggle.setAttribute('aria-label', show ? 'Show artwork' : 'Show queue');
        ui.queueToggle.classList.toggle('text-accent', show);
    }

    /**
     * Points the download and playlist buttons at the current track.
     *
     * The sheet outlives any one track, so these are updated on every change
     * rather than rendered with an item baked in.
     */
    function pointActionsAt(item) {
        const { download, playlist } = current.ui;

        if (download && item) {
            download.dataset.download = String(item.id);
            download.dataset.downloadUrl = item.src ?? `/app/item/${item.id}/stream`;
            download.dataset.downloadTitle = item.title ?? '';
            download.dataset.downloadType = item.type ?? 'music';
            // Reset: the previous track's state says nothing about this one.
            download.dataset.state = 'idle';
        }

        if (playlist && item) {
            playlist.dataset.items = JSON.stringify([item.id]);
        }
    }

    /* ----------------------------------------------------------- wiring */

    ui.close.addEventListener('click', close);
    ui.toggle.addEventListener('click', () => player.toggle());
    ui.prev.addEventListener('click', () => player.previous());
    ui.next.addEventListener('click', () => player.next());
    ui.shuffle.addEventListener('click', () => player.toggleShuffle());
    ui.repeat.addEventListener('click', () => player.cycleRepeat());

    // Delegated to the existing add-to-playlist flow rather than duplicating
    // it: the menu already knows how to list playlists and create one.
    ui.playlist?.addEventListener('click', () => {
        const items = JSON.parse(ui.playlist.dataset.items ?? '[]');

        if (items.length === 0) return;

        document.dispatchEvent(new CustomEvent('soundchex:add-to-playlist', {
            detail: { items },
        }));
    });

    ui.queueToggle.addEventListener('click', () => {
        showQueue(current.ui.queuePane.hidden);
    });

    ui.seek.addEventListener('click', (event) => {
        const { left, width } = current.ui.seek.getBoundingClientRect();

        player.seekFraction((event.clientX - left) / width);
    });

    // Delegated, because the rows are rebuilt whenever the queue changes.
    ui.queueList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-queue-index]');

        if (!button) return;

        const index = Number(button.dataset.queueIndex);

        // Re-entering the same queue at a chosen point, which is what a queue
        // tap means. play() rebuilds from originalQueue, so pass the live one.
        player.play([...player.queue], index);
    });

    /* -------------------------------------- player events keep it in sync */

    player.on('trackchange', () => {
        if (isOpen()) {
            paint();
            renderQueue();
        }
    });

    player.on('playstate', (playing) => setPlaying(playing));
    player.on('time', ({ current: at, duration }) => {
        if (isOpen()) setTime(at, duration);
    });
    player.on('modechange', () => {
        setModes();

        // Shuffling reorders the queue, so the list is now wrong.
        if (isOpen() && !current.ui.queuePane.hidden) renderQueue();
    });

    /* ------------------------------------ document-level, bound once only */

    if (window.soundchexSheetBound) return;

    window.soundchexSheetBound = true;

    // Opening from the bar. Delegated on document so it survives a swap, and
    // guarded so modified clicks still reach the item page.
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('#np-link');

        if (!link) return;

        event.preventDefault();
        event.stopPropagation();
        open();
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) close();
    });
}

document.addEventListener('livewire:navigated', () => bindNowPlayingSheet());

bindNowPlayingSheet();
