/**
 * The media player behind both the now-playing bar and the video view.
 *
 * One engine, two skins. Music needs a queue and a bar that survives
 * navigation; video needs the same transport controls full-screen. Sharing the
 * engine means seeking, resume, and progress reporting behave identically.
 *
 * Native <audio controls> was the starting point, but its chrome can't be
 * styled and looks pasted-on in a dark app — same problem as the PDF viewer.
 * The element is kept for playback and decoding; only the controls are ours.
 */

const PREFS_KEY = 'soundchex.player.prefs';

export default class MediaPlayer {
    constructor(options = {}) {
        this.el = options.element ?? new Audio();
        this.progressUrlFor = options.progressUrlFor;
        this.csrf = document.querySelector('meta[name="csrf-token"]')?.content;

        this.queue = [];
        this.index = -1;
        this.repeat = 'off'; // off | all | one
        this.shuffle = false;

        // A live blob URL holds its whole file in memory, so exactly one is
        // kept and it is revoked before the next track loads.
        this.localSourceUrl = null;

        // Incremented per load, so a slow IndexedDB lookup can tell it has
        // been superseded by a track the listener has since skipped to.
        this.loadToken = 0;

        // Order before shuffling, so turning it off restores the real order
        // rather than leaving the queue permanently scrambled.
        this.originalQueue = [];

        this.listeners = {};

        const prefs = this.loadPrefs();
        this.el.volume = prefs.volume;
        this.repeat = prefs.repeat;
        this.shuffle = prefs.shuffle;

        this.bindElement();
    }

    /* ----------------------------------------------------------- queue */

    /**
     * Replaces the queue and starts at the given index.
     *
     * @param {Array<object>} items Each: {id, title, subtitle, artwork, src, type, resumeAt}
     */
    play(items, startIndex = 0) {
        this.originalQueue = [...items];
        this.queue = this.shuffle ? this.shuffled(items, startIndex) : [...items];

        this.index = this.shuffle ? 0 : startIndex;
        this.load(this.current());
    }

    /** Appends to the queue without interrupting what's playing. */
    enqueue(items) {
        this.queue.push(...items);
        this.originalQueue.push(...items);
        this.emit('queuechange');
    }

    current() {
        return this.queue[this.index] ?? null;
    }

    next({ auto = false } = {}) {
        if (this.queue.length === 0) return;

        // Repeat-one only auto-repeats; pressing next still moves on, which is
        // what every player does and what people expect.
        if (auto && this.repeat === 'one') {
            this.el.currentTime = 0;
            this.el.play();

            return;
        }

        const last = this.index >= this.queue.length - 1;

        if (last && this.repeat !== 'all') {
            if (auto) this.emit('ended');

            return;
        }

        this.index = last ? 0 : this.index + 1;
        this.load(this.current());
    }

    previous() {
        if (this.queue.length === 0) return;

        // Restart the track first, the way every player behaves — you only
        // reach the previous track by pressing it twice.
        if (this.el.currentTime > 3) {
            this.el.currentTime = 0;

            return;
        }

        this.index = this.index <= 0 ? this.queue.length - 1 : this.index - 1;
        this.load(this.current());
    }

    load(item) {
        if (!item) return;

        // The previous track's blob URL pins its whole file in memory, which
        // for a film is gigabytes. Released before the next one is created.
        this.releaseLocalSource();

        this.el.src = item.src;

        // Resume where playback stopped, unless it was effectively finished.
        if (item.resumeAt && item.resumeAt > 5) {
            this.el.currentTime = item.resumeAt;
        }

        this.el.play().catch(() => {
            // Autoplay policies block playback until the user interacts; the
            // UI still shows the loaded track, so pressing play works.
        });

        this.emit('trackchange', item);
        this.updateMediaSession(item);

        // Swapped in after playback starts rather than awaited before it: the
        // lookup is fast but not instant, and blocking every track on an
        // IndexedDB read would add a stutter for the common case of a file
        // that was never downloaded.
        this.preferLocalSource(item);
    }

    /**
     * Plays from the downloaded copy when there is one.
     *
     * Same player either way — an offline track shouldn't need a separate
     * mode. Falls back silently to the network source already loaded.
     */
    async preferLocalSource(item) {
        if (!item?.id || !window.indexedDB) return;

        const token = ++this.loadToken;

        let url = null;

        try {
            const { localUrl } = await import('./downloads.js');
            url = await localUrl(item.id);
        } catch {
            return;
        }

        // The listener may have skipped tracks while that resolved.
        if (!url || token !== this.loadToken) {
            if (url) URL.revokeObjectURL(url);

            return;
        }

        const position = this.el.currentTime;
        const wasPlaying = !this.el.paused;

        this.localSourceUrl = url;
        this.el.src = url;

        this.el.addEventListener('loadedmetadata', () => {
            // Changing src resets position, and playback may already have
            // started from the network.
            if (position > 0) this.el.currentTime = position;
            if (wasPlaying) this.el.play().catch(() => {});
        }, { once: true });

        this.emit('localsource', item);
    }

    releaseLocalSource() {
        if (!this.localSourceUrl) return;

        URL.revokeObjectURL(this.localSourceUrl);
        this.localSourceUrl = null;
    }

    /* -------------------------------------------------------- transport */

    toggle() {
        this.el.paused ? this.el.play() : this.el.pause();
    }

    seek(seconds) {
        if (Number.isFinite(this.el.duration)) {
            this.el.currentTime = Math.max(0, Math.min(this.el.duration, seconds));
        }
    }

    /** Seeks by a fraction of total duration — what a progress bar click means. */
    seekFraction(fraction) {
        if (Number.isFinite(this.el.duration)) {
            this.el.currentTime = this.el.duration * fraction;
        }
    }

    setVolume(value) {
        this.el.volume = Math.max(0, Math.min(1, value));
        this.savePrefs();
        this.emit('volumechange', this.el.volume);
    }

    cycleRepeat() {
        this.repeat = { off: 'all', all: 'one', one: 'off' }[this.repeat];
        this.savePrefs();
        this.emit('modechange');
    }

    toggleShuffle() {
        this.shuffle = !this.shuffle;

        const playing = this.current();

        if (this.shuffle) {
            this.queue = this.shuffled(this.originalQueue, this.index);
            this.index = 0;
        } else {
            // Restore real order, keeping the current track selected.
            this.queue = [...this.originalQueue];
            this.index = Math.max(0, this.queue.findIndex((i) => i.id === playing?.id));
        }

        this.savePrefs();
        this.emit('modechange');
        this.emit('queuechange');
    }

    /**
     * Fisher-Yates over everything except the current track, which is moved to
     * the front so shuffling never interrupts what's playing.
     */
    shuffled(items, keepIndex) {
        const rest = items.filter((_, i) => i !== keepIndex);

        for (let i = rest.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [rest[i], rest[j]] = [rest[j], rest[i]];
        }

        return items[keepIndex] ? [items[keepIndex], ...rest] : rest;
    }

    /* ---------------------------------------------------------- events */

    bindElement() {
        this.el.addEventListener('timeupdate', () => {
            this.emit('time', { current: this.el.currentTime, duration: this.el.duration });
            this.reportProgress();
        });

        this.el.addEventListener('play', () => this.emit('playstate', true));
        this.el.addEventListener('pause', () => this.emit('playstate', false));
        this.el.addEventListener('ended', () => this.next({ auto: true }));
        this.el.addEventListener('loadedmetadata', () => {
            this.emit('time', { current: this.el.currentTime, duration: this.el.duration });
        });

        // Save on leave too — timeupdate may not have fired since the last
        // throttled write.
        window.addEventListener('pagehide', () => this.reportProgress(true));
    }

    /**
     * Drops every UI listener.
     *
     * The player object outlives the page it was created on, so the bar
     * re-binds to fresh markup after each navigation. Without clearing first
     * the handlers accumulate — five navigations, five handlers, four of them
     * writing to elements that no longer exist.
     */
    resetListeners() {
        this.listeners = {};
    }

    on(event, handler) {
        (this.listeners[event] ??= []).push(handler);

        return this;
    }

    emit(event, payload) {
        (this.listeners[event] ?? []).forEach((handler) => handler(payload));
    }

    /* -------------------------------------------------------- progress */

    /**
     * Posts playback position, at most once every 10 seconds.
     *
     * timeupdate fires ~4x a second; writing each one would mean hundreds of
     * requests per track for a value nobody needs that precisely.
     */
    reportProgress(force = false) {
        const item = this.current();

        if (!item || !this.progressUrlFor) return;
        if (!Number.isFinite(this.el.duration)) return;

        const now = Date.now();

        if (!force && now - (this.lastReport ?? 0) < 10_000) return;

        this.lastReport = now;

        fetch(this.progressUrlFor(item), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrf ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                position: Math.floor(this.el.currentTime),
                duration: Math.floor(this.el.duration),
            }),
            keepalive: true,
        }).catch(() => {
            // A dropped position update isn't worth interrupting playback.
        });
    }

    /**
     * Wires OS-level media keys and lock-screen controls.
     *
     * This is what makes the app feel native on a phone — artwork and title on
     * the lock screen, and the headphone play/pause button working.
     */
    updateMediaSession(item) {
        if (!('mediaSession' in navigator)) return;

        navigator.mediaSession.metadata = new MediaMetadata({
            title: item.title ?? '',
            artist: item.subtitle ?? '',
            album: item.album ?? '',
            artwork: item.artwork ? [{ src: item.artwork, sizes: '512x512' }] : [],
        });

        navigator.mediaSession.setActionHandler('play', () => this.el.play());
        navigator.mediaSession.setActionHandler('pause', () => this.el.pause());
        navigator.mediaSession.setActionHandler('nexttrack', () => this.next());
        navigator.mediaSession.setActionHandler('previoustrack', () => this.previous());
    }

    /* ----------------------------------------------------------- prefs */

    loadPrefs() {
        const defaults = { volume: 1, repeat: 'off', shuffle: false };

        try {
            return { ...defaults, ...JSON.parse(localStorage.getItem(PREFS_KEY) ?? '{}') };
        } catch {
            return defaults;
        }
    }

    savePrefs() {
        try {
            localStorage.setItem(PREFS_KEY, JSON.stringify({
                volume: this.el.volume,
                repeat: this.repeat,
                shuffle: this.shuffle,
            }));
        } catch {
            // Private browsing blocks storage; settings just won't persist.
        }
    }
}

/** Formats seconds as m:ss, or h:mm:ss for anything feature-length. */
export function formatTime(seconds) {
    if (!Number.isFinite(seconds)) return '0:00';

    const total = Math.floor(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
        : `${minutes}:${String(secs).padStart(2, '0')}`;
}
