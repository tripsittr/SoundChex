/**
 * Caption display and track selection.
 *
 * Cues are rendered into our own overlay rather than left to the browser.
 * Native rendering can't be positioned or styled — it sits at the very bottom
 * of the video, directly underneath the controls, and every browser draws it
 * differently. Every track is therefore loaded in "hidden" mode, which still
 * fires cue events but draws nothing.
 */

const PREFS_KEY = 'soundchex.captions';

const FONT_SIZES = { small: 2.4, medium: 3.2, large: 4.2, huge: 5.4 };

export function setupCaptions(video, root) {
    const overlay = document.getElementById('watch-captions');
    const menu = document.getElementById('watch-captions-menu');
    const toggle = document.getElementById('watch-captions-toggle');
    const dot = document.getElementById('watch-captions-dot');

    if (!video || !overlay) return null;

    const prefs = loadPrefs();
    let activeId = null;

    applyStyle(overlay, prefs);

    /* ------------------------------------------------------------ cues */

    const renderCues = (track) => {
        overlay.replaceChildren();

        if (!track || !track.activeCues) return;

        Array.from(track.activeCues).forEach((cue) => {
            const line = document.createElement('div');
            line.className = 'watch-caption-line';

            // Cue text carries the WebVTT tag subset (<i>, <b>, <v>), which
            // the DOM fragment renders properly. Falling back to textContent
            // keeps a malformed cue from injecting anything.
            try {
                line.append(cue.getCueAsHTML());
            } catch {
                line.textContent = cue.text;
            }

            overlay.append(line);
        });
    };

    const bindTrack = (track) => {
        if (!track) return;

        // "hidden" still fires cuechange but suppresses native drawing, which
        // is exactly what we want since we draw them ourselves.
        track.mode = 'hidden';
        track.oncuechange = () => renderCues(track);
    };

    const tracks = () => Array.from(video.textTracks);

    const trackElements = () => Array.from(video.querySelectorAll('track'));

    /**
     * Switches to a track by database id, or turns captions off.
     */
    const select = (id) => {
        activeId = id;

        const elements = trackElements();

        tracks().forEach((track, index) => {
            const element = elements[index];
            const matches = element?.dataset.trackId === String(id);

            if (matches) {
                bindTrack(track);
                renderCues(track);
            } else {
                track.mode = 'disabled';
                track.oncuechange = null;
            }
        });

        if (id === 'off' || id === null) {
            overlay.replaceChildren();
        }

        dot?.classList.toggle('hidden', id === 'off' || id === null);

        menu?.querySelectorAll('[data-track-id]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.trackId === String(id));
            button.setAttribute('aria-checked', button.dataset.trackId === String(id) ? 'true' : 'false');
        });

        prefs.lastTrackLanguage = id === 'off' ? null : languageOf(id);
        savePrefs(prefs);
    };

    const languageOf = (id) => trackElements()
        .find((element) => element.dataset.trackId === String(id))?.srclang ?? null;

    /* ----------------------------------------------------------- menu */

    toggle?.addEventListener('click', (event) => {
        event.stopPropagation();

        const open = menu?.classList.toggle('hidden') === false;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
        if (menu?.contains(event.target) || toggle?.contains(event.target)) return;

        menu?.classList.add('hidden');
        toggle?.setAttribute('aria-expanded', 'false');
    });

    menu?.querySelectorAll('[data-track-id]').forEach((button) => {
        button.addEventListener('click', () => {
            select(button.dataset.trackId === 'off' ? 'off' : Number(button.dataset.trackId));
            menu.classList.add('hidden');
        });
    });

    /* -------------------------------------------------- initial state */

    // Restoring the language rather than the track id means the preference
    // carries across films, which is how someone who always wants English
    // subtitles expects it to behave.
    const restore = () => {
        const elements = trackElements();

        if (elements.length === 0) return;

        const preferred = prefs.lastTrackLanguage
            ? elements.find((el) => el.srclang === prefs.lastTrackLanguage && el.dataset.forced !== '1')
            : elements.find((el) => el.hasAttribute('default'));

        if (preferred) {
            select(Number(preferred.dataset.trackId));
        } else {
            select('off');
        }
    };

    // Text tracks aren't populated until metadata has loaded.
    if (video.readyState >= 1) {
        restore();
    } else {
        video.addEventListener('loadedmetadata', restore, { once: true });
    }

    return {
        select,
        prefs,
        applyStyle: () => applyStyle(overlay, prefs),
        /**
         * Adds a track element for a subtitle downloaded during playback, so
         * it can be chosen without reloading the page.
         */
        addTrack: (subtitle) => {
            const element = document.createElement('track');
            element.kind = subtitle.sdh ? 'captions' : 'subtitles';
            element.src = subtitle.url;
            element.srclang = subtitle.language;
            element.label = subtitle.label;
            element.dataset.trackId = String(subtitle.id);
            video.append(element);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'watch-menu-item';
            button.dataset.trackId = String(subtitle.id);
            button.setAttribute('role', 'menuitemradio');
            button.textContent = subtitle.label;
            button.addEventListener('click', () => {
                select(subtitle.id);
                menu?.classList.add('hidden');
            });

            menu?.querySelector('.watch-menu-empty')?.remove();
            menu?.querySelector('.watch-menu-divider')?.before(button);

            // Loading is async; select once the cues are actually parsed.
            element.addEventListener('load', () => select(subtitle.id), { once: true });
        },
    };
}

/**
 * Caption appearance. Kept deliberately close to the platform conventions —
 * white on a dimmed backdrop is what reads most reliably over arbitrary video.
 */
export function applyStyle(overlay, prefs) {
    overlay.style.setProperty('--caption-size', `${FONT_SIZES[prefs.size] ?? FONT_SIZES.medium}vh`);
    overlay.style.setProperty('--caption-color', prefs.color ?? '#ffffff');
    overlay.style.setProperty('--caption-bg', backgroundFor(prefs));
    overlay.style.setProperty('--caption-font', prefs.font === 'serif'
        ? 'Georgia, serif'
        : (prefs.font === 'mono' ? 'ui-monospace, monospace' : 'inherit'));
    overlay.style.setProperty('--caption-shadow', prefs.background === 'none'
        // With no box behind the text, an outline is the only thing keeping it
        // legible over a bright scene.
        ? '0 0 4px rgba(0,0,0,0.95), 0 2px 6px rgba(0,0,0,0.9)'
        : 'none');
    overlay.style.setProperty('--caption-bottom', `${prefs.position ?? 12}%`);
}

function backgroundFor(prefs) {
    if (prefs.background === 'none') return 'transparent';
    if (prefs.background === 'solid') return 'rgba(0,0,0,0.95)';

    return 'rgba(0,0,0,0.7)';
}

export function loadPrefs() {
    const defaults = {
        size: 'medium',
        color: '#ffffff',
        background: 'dim',
        font: 'sans',
        position: 12,
        lastTrackLanguage: null,
    };

    try {
        return { ...defaults, ...JSON.parse(localStorage.getItem(PREFS_KEY) ?? '{}') };
    } catch {
        return defaults;
    }
}

export function savePrefs(prefs) {
    try {
        localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
    } catch {
        // Private browsing blocks storage; preferences just won't persist.
    }
}
