// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import MediaPlayer, { formatTime } from './player.js';

/**
 * The persistent now-playing bar.
 *
 * Docked at the bottom of every media center page, the way a streaming app
 * keeps playback visible while you browse. Any element carrying `data-play`
 * starts a queue, so a play button can live on a poster, a row, or a detail
 * page without knowing anything about the player.
 */

/**
 * The queue a play control starts, and where to begin in it.
 *
 * Two shapes, because a list and a lone button want different things:
 *
 *   - `data-play` on the control itself — one track, or a queue small enough
 *     that repeating it costs nothing. What a poster and the offline shell use.
 *   - `data-play-queue` on an ancestor — the list's queue, written **once**,
 *     with each row carrying only its `data-play-index` into it.
 *
 * The second exists because the first was used for both. A 48-song page
 * serialised the same 26 KB queue onto two buttons per row — 96 copies, 2.5 MB
 * of the 3 MB the page weighed, and a `JSON.parse` of 26 KB on every tap.
 *
 * Named `data-play-queue`, not `data-queue`: the track menu already uses that
 * for the single track the menu acts on, and it sits *inside* each row — so
 * `closest('[data-queue]')` from a row button would find the menu's one track
 * and play that instead of the list.
 *
 * Nearest wins: a row inside a list with its own `data-play` still means
 * itself, so a single-track control never picks up the list around it.
 */
function queueFor(trigger) {
    // Parsing happens here rather than in the handler because the handler has
    // to know there is something to play *before* it swallows the click.
    // A malformed payload reads the same as no payload: nothing to play.
    try {
        const own = trigger.dataset.play;

        if (own !== undefined) {
            const payload = JSON.parse(own);

            return Array.isArray(payload) ? payload : [payload];
        }

        const list = trigger.closest('[data-play-queue]');

        // No queue and no payload is a control that is not a play control
        // after all — a `data-play-index` left on something else.
        if (!list) return null;

        const queue = JSON.parse(list.dataset.playQueue);

        return Array.isArray(queue) ? queue : null;
    } catch {
        return null;
    }
}

/**
 * Binds the bar in the *current* document.
 *
 * Deliberately not a module-scope constant. An SPA swap replaces the whole
 * body, and a reference captured once would keep pointing at the detached
 * element from the previous page — the bar would render but never update.
 */
/**
 * Creates the player singleton and binds playback, without needing the bar.
 *
 * The `data-play` click delegation and the player object are what make anything
 * play; the now-playing *bar* is only their readout. Splitting them out lets the
 * offline shell — which renders its own screens and has no server-rendered bar —
 * start playback of a downloaded track by calling this. Online, `bindNowPlaying`
 * calls it too, so there is one player and one set of delegated handlers.
 *
 * Idempotent: the player is reused from `window`, and the document-level
 * delegation is bound exactly once (guarded by `window.soundchexDelegated`).
 *
 * @returns {MediaPlayer}
 */
export function ensurePlayback() {
    const player = window.soundchexPlayer ?? new MediaPlayer({
        // The progress-report URL. Online the bar carries the template; offline
        // there is no bar and no server to report to, so fall back to the known
        // route shape. Reporting fails harmlessly offline.
        progressUrlFor: (item) => {
            const bar = document.getElementById('now-playing');
            const template = bar?.dataset.progressTemplate;

            return template
                ? template.replace('__ID__', item.id)
                : `/app/item/${item.id}/progress`;
        },
    });

    window.soundchexPlayer = player;

    // Delegated on `document`, which survives an SPA swap, so bound exactly once.
    if (!window.soundchexDelegated) {
        window.soundchexDelegated = true;
        bindPlayDelegation(player);
    }

    return player;
}

/** The `data-play` / keyboard delegation, bound once on `document`. */
function bindPlayDelegation(player) {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-play], [data-play-index]');

        if (!trigger) return;

        const items = queueFor(trigger);

        if (items === null) return;

        event.preventDefault();
        event.stopPropagation();

        try {
            const startIndex = Number(trigger.dataset.playIndex ?? 0);

            if (trigger.dataset.playShuffle !== undefined && !player.shuffle) {
                player.toggleShuffle();
            }

            player.play(items, startIndex);
        } catch {
            // Starting playback shouldn't break the page.
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.target.matches('input, textarea, select')) return;

        if (event.code === 'Space') {
            event.preventDefault();
            player.toggle();
        }

        if (event.code === 'ArrowRight' && event.shiftKey) player.seek(player.el.currentTime + 10);
        if (event.code === 'ArrowLeft' && event.shiftKey) player.seek(player.el.currentTime - 10);
    });
}

function bindNowPlaying() {
    // The player and play-button delegation exist with or without the bar.
    const player = ensurePlayback();

    const bar = document.getElementById('now-playing');

    if (!bar) return;

    // Nothing below may close over `bar` or `ui` directly. This function runs
    // again on every swap — and twice on first load, since livewire:navigated
    // also fires there — so a captured reference would be the *previous*
    // page's detached element while the live bar sat unwritten. Handlers read
    // through `current` instead, which each run replaces.
    const current = (window.soundchexBar ??= {});

    // Anything that decorates the player — the full-screen sheet — may have
    // loaded before this module did, so announce readiness rather than relying
    // on bundle order.
    document.dispatchEvent(new CustomEvent('soundchex:player-ready'));

    // The handlers below write into this page's elements, which are replaced
    // on every navigation. Clearing first keeps exactly one live set.
    player.resetListeners();

    const ui = {
        artwork: document.getElementById('np-artwork'),
        title: document.getElementById('np-title'),
        subtitle: document.getElementById('np-subtitle'),
        toggle: document.getElementById('np-toggle'),
        toggleIcon: document.getElementById('np-toggle-icon'),
        prev: document.getElementById('np-prev'),
        next: document.getElementById('np-next'),
        shuffle: document.getElementById('np-shuffle'),
        repeat: document.getElementById('np-repeat'),
        seek: document.getElementById('np-seek'),
        played: document.getElementById('np-played'),
        current: document.getElementById('np-current'),
        duration: document.getElementById('np-duration'),
        volume: document.getElementById('np-volume'),
        link: document.getElementById('np-link'),
    };

    // This run's live nodes, visible to the handlers bound on the first run.
    current.bar = bar;
    current.ui = ui;

    const ICON_PLAY = 'M8 5v14l11-7z';
    const ICON_PAUSE = 'M6 5h4v14H6zM14 5h4v14h-4z';

    /* ------------------------------------------------------------ wiring */

    player.on('trackchange', (item) => {
        current.bar.classList.remove('translate-y-full');

        current.ui.title.textContent = item.title ?? '';
        current.ui.subtitle.textContent = item.subtitle ?? '';

        if (item.artwork) {
            current.ui.artwork.src = item.artwork;
            current.ui.artwork.classList.remove('hidden');
        } else {
            current.ui.artwork.classList.add('hidden');
        }

        if (current.ui.link && item.url) current.ui.link.href = item.url;
    });

    player.on('playstate', (playing) => {
        current.ui.toggleIcon.setAttribute('d', playing ? ICON_PAUSE : ICON_PLAY);
        current.ui.toggle.setAttribute('aria-label', playing ? 'Pause' : 'Play');
    });

    // The payload field is renamed on the way in: an unrenamed `current` would
    // shadow the shared reference and write the time into itself.
    player.on('time', ({ current: at, duration }) => {
        const fraction = duration ? (at / duration) * 100 : 0;

        current.ui.played.style.width = `${fraction}%`;
        current.ui.current.textContent = formatTime(at);
        current.ui.duration.textContent = formatTime(duration);
    });

    player.on('modechange', () => {
        current.ui.shuffle.classList.toggle('text-accent', player.shuffleMode !== 'off');
        // The dot distinguishes smart from ordinary shuffle, the same way
        // repeat-one is distinguished from repeat-all (S-289).
        current.ui.shuffle.dataset.mode = player.shuffleMode;
        current.ui.shuffle.setAttribute(
            'aria-label',
            { off: 'Shuffle', on: 'Shuffle on — press for smart shuffle', smart: 'Smart shuffle on' }[player.shuffleMode],
        );
        current.ui.repeat.classList.toggle('text-accent', player.repeat !== 'off');
        // A small badge is the clearest way to distinguish repeat-one from
        // repeat-all without a second icon.
        current.ui.repeat.dataset.mode = player.repeat;
    });

    // Bound to this run's nodes, which is correct: these elements are replaced
    // by the swap, and the incoming ones come through here on the next run.
    ui.toggle.addEventListener('click', () => player.toggle());
    ui.prev.addEventListener('click', () => player.previous());
    ui.next.addEventListener('click', () => player.next());
    ui.shuffle.addEventListener('click', () => player.toggleShuffle());
    ui.repeat.addEventListener('click', () => player.cycleRepeat());

    // Clicking or dragging anywhere on the bar seeks proportionally.
    ui.seek.addEventListener('click', (event) => {
        const { left, width } = ui.seek.getBoundingClientRect();
        player.seekFraction((event.clientX - left) / width);
    });

    ui.volume.addEventListener('input', (event) => {
        player.setVolume(Number(event.target.value) / 100);
    });

    ui.volume.value = String(Math.round(player.el.volume * 100));

    // The bar starts hidden and is filled in by the trackchange handler. After
    // a swap — and on the second run of the first load — that event has
    // already fired, so the incoming markup would stay blank and hidden while
    // audio kept playing. Repainting from the player's own state fixes that
    // without waiting for the next event.
    const playing = player.queue[player.index];

    if (playing) {
        bar.classList.remove('translate-y-full');

        ui.title.textContent = playing.title ?? '';
        ui.subtitle.textContent = playing.subtitle ?? '';

        if (playing.artwork) {
            ui.artwork.src = playing.artwork;
            ui.artwork.classList.remove('hidden');
        }

        if (ui.link && playing.url) ui.link.href = playing.url;

        ui.toggleIcon.setAttribute('d', player.el.paused ? ICON_PLAY : ICON_PAUSE);
        ui.current.textContent = formatTime(player.el.currentTime);
        ui.duration.textContent = formatTime(player.el.duration);
    }

    // The play-button and keyboard delegation is bound once by ensurePlayback()
    // (called at the top of this function), independent of the bar.
}

// Re-bound after every swap, because the incoming markup carries a fresh bar
// while the player object itself survives on `window`.
document.addEventListener('livewire:navigated', () => bindNowPlaying());

bindNowPlaying();
