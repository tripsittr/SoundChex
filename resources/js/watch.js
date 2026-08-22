import { logFailure } from './log.js';

import MediaPlayer, { formatTime } from './player.js';
import { setupCaptions, applyStyle, savePrefs } from './watch-captions.js';

/**
 * Full-screen video player.
 *
 * Shares the transport engine with the now-playing bar, so seeking, resume,
 * and progress reporting behave identically to audio. What differs is the
 * chrome: controls overlay the picture and fade out while watching, the way
 * every streaming player works.
 */

const root = document.getElementById('watch');

if (root) {
    const video = document.getElementById('watch-video');

    const player = new MediaPlayer({
        element: video,
        progressUrlFor: () => root.dataset.progressUrl,
    });

    const markers = JSON.parse(root.dataset.markers || '{}');

    // The item is loaded by the <video> element's own src, so the queue is
    // seeded directly rather than through play().
    player.queue = [{ id: root.dataset.itemId, title: root.dataset.title }];
    player.index = 0;

    // A downloaded film plays from the device. The <video> element already
    // has its network src, so this swaps in the local copy if there is one and
    // otherwise leaves it alone.
    preferDownloadedVideo(video, root.dataset.itemId);

    const captions = setupCaptions(video, root);

    setupCaptionSearch(root, captions);
    setupCaptionAppearance(captions);

    const resumeAt = Number(root.dataset.resumeAt || 0);

    const ui = {
        chrome: document.getElementById('watch-chrome'),
        toggle: document.getElementById('watch-toggle'),
        toggleIcon: document.getElementById('watch-toggle-icon'),
        back: document.getElementById('watch-back10'),
        forward: document.getElementById('watch-forward10'),
        seek: document.getElementById('watch-seek'),
        played: document.getElementById('watch-played'),
        buffered: document.getElementById('watch-buffered'),
        current: document.getElementById('watch-current'),
        duration: document.getElementById('watch-duration'),
        volume: document.getElementById('watch-volume'),
        mute: document.getElementById('watch-mute'),
        fullscreen: document.getElementById('watch-fullscreen'),
        skip: document.getElementById('watch-skip'),
        spinner: document.getElementById('watch-spinner'),
    };

    const ICON_PLAY = 'M8 5v14l11-7z';
    const ICON_PAUSE = 'M6 5h4v14H6zM14 5h4v14h-4z';

    /* --------------------------------------------------------- resume */

    // Resuming before metadata loads is ignored — duration isn't known yet, so
    // the seek silently does nothing.
    video.addEventListener('loadedmetadata', () => {
        if (resumeAt > 5 && resumeAt < video.duration - 30) {
            video.currentTime = resumeAt;
        }
    }, { once: true });

    /* -------------------------------------------------------- transport */

    player.on('playstate', (playing) => {
        ui.toggleIcon.setAttribute('d', playing ? ICON_PAUSE : ICON_PLAY);
        ui.toggle.setAttribute('aria-label', playing ? 'Pause' : 'Play');

        // Controls linger while paused; nobody wants to hunt for play.
        playing ? scheduleHide() : showChrome({ sticky: true });
    });

    player.on('time', ({ current, duration }) => {
        ui.played.style.width = duration ? `${(current / duration) * 100}%` : '0';
        ui.current.textContent = formatTime(current);
        ui.duration.textContent = formatTime(duration);

        updateSkipButton(current);
        updateBuffered();
    });

    ui.toggle.addEventListener('click', () => player.toggle());
    ui.back.addEventListener('click', () => player.seek(video.currentTime - 10));
    ui.forward.addEventListener('click', () => player.seek(video.currentTime + 10));

    ui.seek.addEventListener('click', (event) => {
        const { left, width } = ui.seek.getBoundingClientRect();
        player.seekFraction((event.clientX - left) / width);
    });

    ui.volume.addEventListener('input', (event) => {
        player.setVolume(Number(event.target.value) / 100);
        video.muted = false;
    });

    ui.volume.value = String(Math.round(video.volume * 100));

    ui.mute.addEventListener('click', () => {
        video.muted = !video.muted;
        ui.mute.classList.toggle('text-accent', video.muted);
    });

    ui.fullscreen.addEventListener('click', () => {
        document.fullscreenElement
            ? document.exitFullscreen()
            : root.requestFullscreen?.();
    });

    /* ------------------------------------------------------ skip markers */

    let activeMarker = null;

    function updateSkipButton(current) {
        const match = Object.entries(markers).find(([, marker]) => {
            // Credits have no end, so the window runs to the file's end.
            const end = marker.end ?? Infinity;

            return current >= marker.start && current < end;
        });

        if (!match) {
            if (activeMarker) {
                activeMarker = null;
                ui.skip.classList.add('opacity-0', 'pointer-events-none');
            }

            return;
        }

        const [key, marker] = match;

        if (activeMarker === key) return;

        activeMarker = key;
        ui.skip.textContent = marker.label;
        ui.skip.classList.remove('opacity-0', 'pointer-events-none');
    }

    ui.skip.addEventListener('click', () => {
        const marker = markers[activeMarker];

        if (!marker) return;

        // Credits skip to the end, which ends playback and lets whatever
        // follows take over.
        player.seek(marker.end ?? video.duration);
    });

    /* ------------------------------------------------------------ buffer */

    function updateBuffered() {
        if (!video.buffered.length || !video.duration) return;

        const end = video.buffered.end(video.buffered.length - 1);
        ui.buffered.style.width = `${(end / video.duration) * 100}%`;
    }

    video.addEventListener('progress', updateBuffered);
    video.addEventListener('waiting', () => ui.spinner.classList.remove('hidden'));
    video.addEventListener('playing', () => ui.spinner.classList.add('hidden'));
    video.addEventListener('canplay', () => ui.spinner.classList.add('hidden'));

    /* ------------------------------------------------------------ chrome */

    let hideTimer;

    function showChrome({ sticky = false } = {}) {
        ui.chrome.classList.remove('opacity-0');
        root.classList.remove('cursor-none');
        clearTimeout(hideTimer);

        if (!sticky) scheduleHide();
    }

    function scheduleHide() {
        clearTimeout(hideTimer);

        hideTimer = setTimeout(() => {
            if (video.paused) return;

            ui.chrome.classList.add('opacity-0');
            // Hiding the cursor too is what makes it feel like a player
            // rather than a web page with a video on it.
            root.classList.add('cursor-none');
        }, 2800);
    }

    root.addEventListener('mousemove', () => showChrome());
    root.addEventListener('touchstart', () => showChrome());

    // Clicking the picture toggles playback, but not when the click landed on
    // a control.
    video.addEventListener('click', () => player.toggle());

    /* ---------------------------------------------------------- keyboard */

    document.addEventListener('keydown', (event) => {
        if (event.target.matches('input, textarea, select')) return;

        const actions = {
            Space: () => player.toggle(),
            KeyK: () => player.toggle(),
            ArrowLeft: () => player.seek(video.currentTime - 10),
            ArrowRight: () => player.seek(video.currentTime + 10),
            KeyJ: () => player.seek(video.currentTime - 10),
            KeyL: () => player.seek(video.currentTime + 10),
            KeyF: () => ui.fullscreen.click(),
            KeyM: () => ui.mute.click(),
            ArrowUp: () => player.setVolume(video.volume + 0.1),
            ArrowDown: () => player.setVolume(video.volume - 0.1),
        };

        const action = actions[event.code];

        if (action) {
            event.preventDefault();
            action();
            showChrome();
        }
    });

    // Kick the chrome timer once so it fades if playback starts immediately.
    showChrome();
}

/* --------------------------------------------------------------- captions */

/**
 * Online subtitle search.
 *
 * Deliberately a dialog rather than something automatic: OpenSubtitles
 * rate-limits downloads per account, so a search happens when someone asks
 * for one and not on every film that opens.
 */
function setupCaptionSearch(root, captions) {
    const trigger = document.getElementById('watch-captions-search');
    const dialog = document.getElementById('watch-subtitle-search');
    const results = document.getElementById('watch-subtitle-results');
    const status = document.getElementById('watch-subtitle-status');
    const menu = document.getElementById('watch-captions-menu');

    if (!trigger || !dialog) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const close = () => dialog.classList.add('hidden');

    document.getElementById('watch-subtitle-close')?.addEventListener('click', close);

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) close();
    });

    trigger.addEventListener('click', async () => {
        menu?.classList.add('hidden');
        dialog.classList.remove('hidden');
        results.replaceChildren();
        status.textContent = 'Searching…';

        const language = document.getElementById('watch-subtitle-language')?.value ?? 'en';

        let data;

        try {
            const response = await fetch(`${menu.dataset.searchUrl}?languages=${encodeURIComponent(language)}`, {
                headers: { Accept: 'application/json' },
            });

            data = await response.json();

            if (!response.ok) {
                status.textContent = data.message ?? 'Search failed.';

                return;
            }
        } catch (error) {
            logFailure('subtitles:search:failed', error, { item: itemId ?? null });

            status.textContent = 'Could not reach the subtitle service.';

            return;
        }

        if (!data.results?.length) {
            status.textContent = 'No subtitles found for this title.';

            return;
        }

        status.textContent = `${data.results.length} found`;

        data.results.slice(0, 25).forEach((result) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'watch-subtitle-result';

            const name = document.createElement('span');
            name.className = 'watch-subtitle-name';
            name.textContent = result.name;

            const meta = document.createElement('span');
            meta.className = 'watch-subtitle-meta';

            const bits = [result.language.toUpperCase()];

            // The strongest signal available: a hash match is tied to this
            // exact file, so the timing will line up.
            if (result.hash_match) bits.push('✓ matches your file');
            if (result.hearing_impaired) bits.push('SDH');
            if (result.forced) bits.push('Forced');
            if (result.downloads) bits.push(`${result.downloads.toLocaleString()} downloads`);

            meta.textContent = bits.join(' · ');

            row.append(name, meta);

            row.addEventListener('click', async () => {
                row.disabled = true;
                status.textContent = 'Downloading…';

                try {
                    const response = await fetch(menu.dataset.searchUrl.replace('/search', '/download'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            file_id: result.file_id,
                            language: result.language,
                            forced: result.forced,
                            hearing_impaired: result.hearing_impaired,
                        }),
                    });

                    const payload = await response.json();

                    if (!response.ok) {
                        status.textContent = payload.message ?? 'Download failed.';
                        row.disabled = false;

                        return;
                    }

                    captions?.addTrack({ ...payload.subtitle, sdh: result.hearing_impaired });
                    status.textContent = 'Added — now playing with these subtitles.';
                    setTimeout(close, 900);
                } catch {
                    status.textContent = 'Download failed.';
                    row.disabled = false;
                }
            });

            results.append(row);
        });
    });
}

/**
 * Caption appearance: size, colour, backdrop, position.
 *
 * Worth having because captions sit over arbitrary video — what reads well
 * over a dark film is unreadable over a bright one.
 */
function setupCaptionAppearance(captions) {
    const trigger = document.getElementById('watch-captions-style');
    const panel = document.getElementById('watch-caption-style');
    const menu = document.getElementById('watch-captions-menu');

    if (!trigger || !panel || !captions) return;

    trigger.addEventListener('click', () => {
        menu?.classList.add('hidden');
        panel.classList.toggle('hidden');
    });

    document.getElementById('watch-caption-style-close')?.addEventListener('click', () => {
        panel.classList.add('hidden');
    });

    const bind = (id, key, transform = (value) => value) => {
        const control = document.getElementById(id);

        if (!control) return;

        // Reflect the stored preference before listening, so the controls
        // open showing what's actually in effect.
        if (control.type === 'range') {
            control.value = String(captions.prefs[key]);
        } else if (control.tagName === 'SELECT' || control.type === 'color') {
            control.value = captions.prefs[key];
        }

        control.addEventListener('input', () => {
            captions.prefs[key] = transform(control.value);
            captions.applyStyle();
            savePrefs(captions.prefs);
        });
    };

    bind('watch-caption-size', 'size');
    bind('watch-caption-color', 'color');
    bind('watch-caption-bg', 'background');
    bind('watch-caption-font', 'font');
    bind('watch-caption-position', 'position', Number);
}

/**
 * Swaps a downloaded copy in for the network stream.
 *
 * Position and play state are preserved, since this resolves after playback
 * may already have begun. The blob URL is revoked when the page unloads — a
 * live URL pins a multi-gigabyte file in memory.
 */
async function preferDownloadedVideo(video, itemId) {
    if (!video || !itemId || !window.indexedDB) return;

    let url = null;

    try {
        const { localUrl } = await import('./downloads.js');
        url = await localUrl(itemId);
    } catch (error) {
        // Falls back to streaming, which is the right behaviour and entirely
        // invisible: someone who downloaded a film for a flight would find it
        // streaming instead, with nothing anywhere saying the stored copy
        // could not be read.
        logFailure('watch:local-source:failed', error, { item: itemId ?? null });

        return;
    }

    if (!url) return;

    const position = video.currentTime;
    const wasPlaying = !video.paused;

    video.src = url;

    video.addEventListener('loadedmetadata', () => {
        if (position > 0) video.currentTime = position;
        if (wasPlaying) video.play().catch(() => {});
    }, { once: true });

    document.getElementById('watch-offline-badge')?.classList.remove('hidden');

    window.addEventListener('pagehide', () => URL.revokeObjectURL(url), { once: true });
}
