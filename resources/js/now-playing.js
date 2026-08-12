import MediaPlayer, { formatTime } from './player.js';

/**
 * The persistent now-playing bar.
 *
 * Docked at the bottom of every media center page, the way a streaming app
 * keeps playback visible while you browse. Any element carrying `data-play`
 * starts a queue, so a play button can live on a poster, a row, or a detail
 * page without knowing anything about the player.
 */

const bar = document.getElementById('now-playing');

if (bar) {
    const player = new MediaPlayer({
        progressUrlFor: (item) => bar.dataset.progressTemplate.replace('__ID__', item.id),
    });

    window.soundchexPlayer = player;

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

    const ICON_PLAY = 'M8 5v14l11-7z';
    const ICON_PAUSE = 'M6 5h4v14H6zM14 5h4v14h-4z';

    /* ------------------------------------------------------------ wiring */

    player.on('trackchange', (item) => {
        bar.classList.remove('translate-y-full');

        ui.title.textContent = item.title ?? '';
        ui.subtitle.textContent = item.subtitle ?? '';

        if (item.artwork) {
            ui.artwork.src = item.artwork;
            ui.artwork.classList.remove('hidden');
        } else {
            ui.artwork.classList.add('hidden');
        }

        if (ui.link && item.url) ui.link.href = item.url;
    });

    player.on('playstate', (playing) => {
        ui.toggleIcon.setAttribute('d', playing ? ICON_PAUSE : ICON_PLAY);
        ui.toggle.setAttribute('aria-label', playing ? 'Pause' : 'Play');
    });

    player.on('time', ({ current, duration }) => {
        const fraction = duration ? (current / duration) * 100 : 0;

        ui.played.style.width = `${fraction}%`;
        ui.current.textContent = formatTime(current);
        ui.duration.textContent = formatTime(duration);
    });

    player.on('modechange', () => {
        ui.shuffle.classList.toggle('text-accent', player.shuffle);
        ui.repeat.classList.toggle('text-accent', player.repeat !== 'off');
        // A small badge is the clearest way to distinguish repeat-one from
        // repeat-all without a second icon.
        ui.repeat.dataset.mode = player.repeat;
    });

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

    /* ------------------------------------------------------- play buttons */

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
