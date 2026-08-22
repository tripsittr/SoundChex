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
 * Binds the bar in the *current* document.
 *
 * Deliberately not a module-scope constant. An SPA swap replaces the whole
 * body, and a reference captured once would keep pointing at the detached
 * element from the previous page — the bar would render but never update.
 */
function bindNowPlaying() {
    const bar = document.getElementById('now-playing');

    if (!bar) return;

    // Nothing below may close over `bar` or `ui` directly. This function runs
    // again on every swap — and twice on first load, since livewire:navigated
    // also fires there — so a captured reference would be the *previous*
    // page's detached element while the live bar sat unwritten. Handlers read
    // through `current` instead, which each run replaces.
    const current = (window.soundchexBar ??= {});

    // Reused across navigations rather than recreated.
    //
    // Livewire swaps the whole body, which re-executes this module — and the
    // <audio> element lives on this object, created in JS rather than markup,
    // so @persist cannot protect it. A new MediaPlayer here would mean a new
    // <audio>, and playback would stop on every link exactly as it did before.
    //
    // The object survives because `window` does. Only the DOM bindings below
    // are re-attached to the incoming markup.
    const player = window.soundchexPlayer ?? new MediaPlayer({
        progressUrlFor: (item) => bar.dataset.progressTemplate.replace('__ID__', item.id),
    });

    window.soundchexPlayer = player;

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
        current.ui.shuffle.classList.toggle('text-accent', player.shuffle);
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

    /* ------------------------------------------------------- play buttons */

    // These two are delegated on `document`, which survives an SPA swap — so
    // unlike the bindings above they must be attached exactly once. Re-adding
    // them per navigation would stack handlers and fire one click N times.
    //
    // The flag lives on `window` for the same reason the player does: body and
    // its dataset are replaced by the swap, so a marker there would reset and
    // defeat the guard. They close over the singleton player, so binding once
    // stays correct.
    if (window.soundchexDelegated) return;

    window.soundchexDelegated = true;

    // Delegated so buttons rendered after load (or inside rails) still work.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-play]');

        if (!trigger) return;

        // Play buttons sit inside the poster's link, so the click has to be
        // stopped from bubbling or pressing play would also navigate away.
        event.preventDefault();
        event.stopPropagation();

        try {
            const payload = JSON.parse(trigger.dataset.play);
            const items = Array.isArray(payload) ? payload : [payload];
            const startIndex = Number(trigger.dataset.playIndex ?? 0);

            // "Shuffle this album" is a different intent from "shuffle
            // whatever is playing", so the trigger turns the mode on rather
            // than the user setting it first and pressing play second.
            if (trigger.dataset.playShuffle !== undefined && !player.shuffle) {
                player.toggleShuffle();
            }

            player.play(items, startIndex);
        } catch {
            // A malformed payload shouldn't break the page.
        }
    });

    /* ---------------------------------------------------------- keyboard */

    document.addEventListener('keydown', (event) => {
        if (event.target.matches('input, textarea, select')) return;

        if (event.code === 'Space') {
            event.preventDefault();
            player.toggle();
        }

        // Arrow keys scrub, matching how most players behave.
        if (event.code === 'ArrowRight' && event.shiftKey) player.seek(player.el.currentTime + 10);
        if (event.code === 'ArrowLeft' && event.shiftKey) player.seek(player.el.currentTime - 10);
    });
}

// Re-bound after every swap, because the incoming markup carries a fresh bar
// while the player object itself survives on `window`.
document.addEventListener('livewire:navigated', () => bindNowPlaying());

bindNowPlaying();
