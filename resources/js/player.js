// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { log, logFailure } from './log.js';

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

/**
 * Where a play was started from, inferred from the current page path (S-120).
 * Maps to the server's known set (MediaPlay::SOURCES); an unrecognised page maps
 * to null so nothing wrong is recorded.
 *
 * @returns {string|null}
 */
function currentSource() {
    const path = window.location?.pathname ?? '';

    if (/^\/app\/album(\/|$|\?)/.test(path)) return 'album';
    if (/^\/app\/artist(\/|$|\?)/.test(path)) return 'artist';
    if (/^\/app\/playlist/.test(path)) return 'playlist';
    if (/^\/app\/search/.test(path)) return 'search';
    if (/^\/app\/item\//.test(path)) return 'show';
    if (/^\/app\/(music|movies|shows|books|browse)/.test(path)) return 'browse';
    if (path === '/app' || path === '/app/') return 'home';

    return null;
}

/**
 * Append `?from=<source>` to a stream URL when the page maps to a known source.
 *
 * @param {string} src
 * @returns {string}
 */
function withSource(src) {
    const source = currentSource();

    if (!source || typeof src !== 'string') return src;

    try {
        const url = new URL(src, window.location.origin);
        url.searchParams.set('from', source);

        return url.pathname + url.search + url.hash;
    } catch {
        // A relative or odd src — fall back to a simple append.
        return src + (src.includes('?') ? '&' : '?') + 'from=' + source;
    }
}

/**
 * Where the queue and position live between page loads.
 *
 * sessionStorage rather than local: this is "what I am listening to right now",
 * which should not come back a week later, but must survive a full page load.
 * The reader is a standalone document — deliberately, since an e-reader wants
 * the whole viewport — so opening a book replaces the page entirely and takes
 * the player object with it. Restoring from here is what stops the music
 * stopping.
 */
const SESSION_KEY = 'soundchex.player.session';

export default class MediaPlayer {
    constructor(options = {}) {
        this.el = options.element ?? new Audio();
        this.progressUrlFor = options.progressUrlFor;
        this.csrf = document.querySelector('meta[name="csrf-token"]')?.content;

        this.queue = [];
        this.index = -1;
        this.repeat = 'off'; // off | all | one
        this.shuffle = false;
        /** 'off' | 'on' | 'smart' — the third state is S-289. */
        this.shuffleMode = 'off';

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
        // A stored boolean predates the three-state button; treat true as 'on'.
        this.shuffleMode = prefs.shuffleMode ?? (prefs.shuffle ? 'on' : 'off');
        this.shuffle = this.shuffleMode !== 'off';

        this.bindElement();
        this.restoreSession();
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

    /**
     * Inserts straight after whatever is playing.
     *
     * Distinct from enqueue(), which appends: "play next" is a promise about
     * position, and dropping the track at the end of a long queue would break
     * it silently.
     *
     * With nothing playing there is no "next", so this starts the items
     * instead — the alternative is a queue the user cannot hear.
     */
    playNext(items) {
        if (this.index < 0) {
            this.play(items, 0);

            return;
        }

        this.queue.splice(this.index + 1, 0, ...items);

        // Mirrored into originalQueue so turning shuffle off later does not
        // resurrect a queue these tracks were never in.
        const anchor = this.originalQueue.indexOf(this.queue[this.index]);

        this.originalQueue.splice(anchor < 0 ? this.originalQueue.length : anchor + 1, 0, ...items);

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

        const token = ++this.loadToken;

        // Local-first, then network. The old order set the network `src`
        // immediately and swapped the local file in afterwards — which offline
        // meant every downloaded track first errored on the unreachable server
        // URL before the local source landed. So: resolve a local copy up front,
        // and only fall to the network when there isn't one. The lookup is a
        // single `has()` then a path resolve, fast enough to precede playback;
        // the common non-downloaded case still goes straight to the network.
        this.loadWithLocalFirst(item, token);

        this.emit('trackchange', item);
        this.updateMediaSession(item);
    }

    /**
     * Sets the audio source, preferring a downloaded copy.
     *
     * Kept off `load()` so `load()` stays synchronous for its callers; the token
     * guards against a track the listener skipped past while this resolved.
     */
    async loadWithLocalFirst(item, token) {
        let localUrl = null;

        try {
            const storage = await import('./offline/storage.js');

            if (await storage.has(item.id)) {
                localUrl = await storage.open(item.id);
            }
        } catch (error) {
            logFailure('player:local-source:failed', error, { id: String(item.id) });
        }

        // Skipped while we resolved — abandon this load.
        if (token !== this.loadToken) {
            if (localUrl && localUrl.startsWith('blob:')) URL.revokeObjectURL(localUrl);

            return;
        }

        if (localUrl) {
            this.localSourceUrl = localUrl;
            this.el.src = localUrl;
        } else {
            // Tell the server where this play was started from (S-120), taken
            // from the page the player is on. A local (downloaded) play does not
            // hit the stream endpoint, so it records no source, which is correct.
            this.el.src = withSource(item.src);
        }

        if (item.resumeAt && item.resumeAt > 5) {
            this.el.currentTime = item.resumeAt;
        }

        this.el.play().catch(() => {
            // Autoplay policies block playback until the user interacts; the
            // UI still shows the loaded track, so pressing play works.
        });
    }

    releaseLocalSource() {
        if (!this.localSourceUrl) return;

        // Only blob URLs need revoking. The native backend returns an asset://
        // URL (convertFileSrc), which is not an object URL — revoking it is a
        // no-op, but guarding keeps the intent clear and avoids pretending the
        // two are the same.
        if (this.localSourceUrl.startsWith('blob:')) {
            URL.revokeObjectURL(this.localSourceUrl);
        }

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

    /**
     * Cycles shuffle: off → on → smart → off (S-289).
     *
     * Smart shuffle is a third state of the same button rather than a separate
     * control, so the three live where one already did and nothing new has to
     * be found on the bar.
     *
     * It asks the server for a queue weighted by what this profile plays,
     * because weighting it here would mean the browser holding the whole
     * library and the whole play history. Until that arrives the current
     * queue stays exactly as it is — a button that empties the player while
     * a request is in flight is worse than one that takes a moment.
     */
    async toggleShuffle() {
        this.shuffleMode = { off: 'on', on: 'smart', smart: 'off' }[this.shuffleMode] ?? 'on';

        // Kept in step for anything still reading the old boolean.
        this.shuffle = this.shuffleMode !== 'off';

        const playing = this.current();

        if (this.shuffleMode === 'smart') {
            this.savePrefs();
            this.emit('modechange');

            await this.loadSmartQueue();

            return;
        }

        if (this.shuffleMode === 'on') {
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
     * Replaces the queue with a server-weighted one, keeping what is playing.
     *
     * A failure leaves the queue alone and falls back to ordinary shuffle:
     * the listener pressed a button and something should happen, and silently
     * staying in "smart" while behaving uniformly would be a lie.
     */
    async loadSmartQueue() {
        const playing = this.current();

        try {
            const response = await fetch('/app/shuffle?smart=1', {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) throw new Error(String(response.status));

            const { queue } = await response.json();

            if (!queue?.length) throw new Error('empty');

            // What is playing stays playing and stays first; the weighted
            // queue follows it.
            const rest = queue.filter((item) => item.id !== playing?.id);

            this.queue = playing ? [playing, ...rest] : rest;
            this.originalQueue = [...this.queue];
            this.index = 0;
        } catch (error) {
            logFailure('player:smart-shuffle:failed', error);

            this.shuffleMode = 'on';
            this.queue = this.shuffled(this.originalQueue, this.index);
            this.index = 0;
            this.savePrefs();
            this.emit('modechange');
        }

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
            this.rememberPosition();
        });

        // Written on the transitions that matter, so a page load lands on the
        // right track in the right state rather than wherever the last
        // throttled tick happened to leave it.
        // `wanted` is what the listener asked for, which is not always what
        // the element reports: a browser pauses the audio while tearing the
        // page down, so a save driven by the element's own state during
        // navigation records "paused" for music that was playing, and the next
        // page restores it stopped.
        this.el.addEventListener('play', () => {
            this.wantedPlaying = true;
            this.saveSession();
        });

        this.el.addEventListener('pause', () => {
            // Only a pause the page is still alive for counts as a decision.
            if (document.visibilityState !== 'hidden') {
                this.wantedPlaying = false;
            }

            this.saveSession();
        });

        this.el.addEventListener('loadedmetadata', () => this.saveSession());

        // A track that will not play was doing so in silence: no message, no
        // record, nothing to look up. The element's own error is the only
        // thing that says which of the four reasons it was — a decode failure
        // reads very differently from a 404, and "the music didn't work" is
        // indistinguishable between them without this.
        this.el.addEventListener('error', () => {
            const codes = {
                1: 'aborted',
                2: 'network',
                3: 'decode',
                4: 'unsupported source',
            };

            const item = this.queue?.[this.index] ?? null;

            log('playback:failed', {
                item: item?.id ?? null,
                title: item?.title ?? null,
                cause: codes[this.el.error?.code] ?? 'unknown',
                // Whether it was playing a downloaded copy or streaming: a
                // stored file failing to decode is a corrupt download, and a
                // stream failing is the network or the server.
                local: String(this.el.currentSrc ?? '').startsWith('blob:'),
                online: navigator.onLine !== false,
            });
        });

        // A phone kills a backgrounded tab without warning, and pagehide is the
        // last event that reliably fires before it goes.
        window.addEventListener('pagehide', () => this.saveSession());

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
            // Offline. Queued rather than dropped: this is the position of a
            // film someone is watching on a train, and losing it means
            // starting over. The queue collapses repeats per item, so a long
            // film leaves one entry rather than hundreds.
            window.soundchexWrites?.enqueue({
                kind: 'progress',
                url: this.progressUrlFor(item),
                body: {
                    position: Math.floor(this.el.currentTime),
                    duration: Math.floor(this.el.duration),
                },
                key: `progress:${item.id}`,
            });
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

    /**
     * Writes down what is playing, so a full page load can pick it up.
     *
     * Position included: resuming a forty-minute track from the beginning is
     * not resuming it. Paused state is carried too — a page load should not
     * start playing something the listener had stopped.
     */
    /**
     * Throttled position write.
     *
     * timeupdate fires several times a second, and serialising the whole queue
     * that often is wasted work on a phone. Once a second is enough to land
     * within a second of where playback actually was.
     */
    rememberPosition() {
        const now = Date.now();

        if (now - (this.lastSessionWrite ?? 0) < 1000) return;

        this.lastSessionWrite = now;
        this.saveSession();
    }

    saveSession() {
        if (this.restoring) return;
        if (this.index < 0 || this.queue.length === 0) return;

        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify({
                queue: this.queue,
                originalQueue: this.originalQueue,
                index: this.index,
                position: this.el.currentTime || 0,
                paused: !(this.wantedPlaying ?? !this.el.paused),
            }));
        } catch {
            // Private browsing, or the quota is full. Playback still works;
            // it just will not survive a page load.
        }
    }

    /**
     * Picks up where the previous page left off.
     *
     * Loaded but not played: a browser will refuse an autoplay that no gesture
     * asked for, and more importantly a page opening itself into sound is
     * hostile. The audio is primed at the right position and the bar shows the
     * track, so one tap resumes it.
     *
     * Except when it was already playing — a page load in the middle of a track
     * is a navigation, not a decision to stop, and the gesture that triggered it
     * is recent enough that the browser allows it.
     */
    restoreSession() {
        // Guarded for the duration of the restore. Loading a source fires
        // pause, and a save triggered by that would overwrite the very state
        // being restored — which is what turned a playing queue into a paused
        // one at position zero the moment the new page came up.
        this.restoring = true;

        try {
            this.applySession();
        } finally {
            this.restoring = false;
        }
    }

    applySession() {
        let state;

        try {
            state = JSON.parse(sessionStorage.getItem(SESSION_KEY) ?? 'null');
        } catch {
            return;
        }

        if (!state || !Array.isArray(state.queue) || state.queue.length === 0) return;
        if (typeof state.index !== 'number' || state.index < 0) return;

        this.queue = state.queue;
        this.originalQueue = Array.isArray(state.originalQueue) ? state.originalQueue : [];
        this.index = Math.min(state.index, state.queue.length - 1);

        const item = this.current();

        if (!item) return;

        // Through the item's own resumeAt, which is how load() already carries
        // a position — rather than a second mechanism that would drift from it.
        this.load({ ...item, resumeAt: state.position ?? 0 });

        // Carried across so the next save records the listener's intent rather
        // than whatever the element happens to report on a fresh page.
        this.wantedPlaying = state.paused === false;

        // A page load in the middle of a track is a navigation, not a decision
        // to stop — but one that was already paused must stay paused, and
        // load() always starts playback.
        if (state.paused !== false) {
            this.el.pause();

            return;
        }

        // A fresh document has no user gesture behind it, so the browser
        // refuses the play() that load() issued — the track is primed at the
        // right position and silent. Nothing can override that policy, so the
        // next tap anywhere on the page resumes it, which is the first moment
        // the browser will allow it.
        this.resumeOnFirstGesture();
    }

    /**
     * Resumes at the first user gesture, once.
     *
     * Only when the listener had it playing: a page load should never turn
     * silence into sound. Bound on pointerdown and keydown rather than click so
     * it fires on the same gesture that scrolls or turns a page, rather than
     * waiting for a deliberate tap on a control.
     */
    resumeOnFirstGesture() {
        const resume = () => {
            detach();

            if (this.wantedPlaying === false) return;

            this.el.play().catch(() => {
                // Still refused. The bar shows the track, so the play button
                // works — there is nothing further to try automatically.
            });
        };

        const detach = () => {
            document.removeEventListener('pointerdown', resume);
            document.removeEventListener('keydown', resume);
            this.el.removeEventListener('play', detach);
        };

        document.addEventListener('pointerdown', resume, { once: true });
        document.addEventListener('keydown', resume, { once: true });

        // Superseded if the listener presses play themselves first.
        this.el.addEventListener('play', detach, { once: true });
    }

    loadPrefs() {
        const defaults = { volume: 1, repeat: 'off', shuffle: false, shuffleMode: null };

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
                shuffleMode: this.shuffleMode,
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
