import * as mirror from './mirror.js';
import * as failover from './failover.js';
import { preRender, serverReachable, takeOver } from './offline-shell.js';
import * as writes from './write-queue.js';
import * as query from './query.js';
import * as sync from './sync.js';

/**
 * The offline library, as one surface.
 *
 * Exposed on `window` for the same reason the downloads module is: the bundle
 * has no importable module path at runtime, and the browser tests drive the
 * real thing rather than a parallel implementation.
 *
 * Nothing here renders. Phase 3 replaces the Blade views with something that
 * reads from this; until then the mirror is built and kept in step so that
 * work has data to render from, and so a sync problem surfaces now rather than
 * in the middle of a UI port.
 */
const library = {
    ...query,
    mirror,
    takeOver,
    preRender,
    serverReachable,
    failover,
    sync: sync.sync,
    signOut: sync.signOut,
    setToken: sync.setToken,
    token: sync.token,
    resyncForProfile: sync.resyncForProfile,

    /** Everything the device holds, for a caller that wants to query it. */
    all: mirror.all,
};

window.soundchexLibrary = library;

// Its own global: the player reaches for this without importing the library,
// so a page that has no mirror still queues its writes.
window.soundchexWrites = writes;

/**
 * Syncs when the app is opened and whenever it comes back to the foreground.
 *
 * Foreground matters more than it looks: a phone suspends the page rather than
 * closing it, so an app left open for a day would otherwise show a day-old
 * library with no indication anything was stale.
 */
function scheduleSync() {
    // No token check here.
    //
    // There used to be one, and it deadlocked: the token is obtained *during*
    // sync, from the session this page already has — so refusing to sync
    // without one meant never getting one, and the mirror stayed permanently
    // empty. sync() returns 'unauthenticated' by itself when there is no
    // session to ask with, which is the correct place to decide that.

    // Queued writes go first. They describe a moment already past, and
    // sending them before pulling means the sync reflects them rather than
    // overwriting the device's view with a server state that predates them.
    writes.flush().then((flushed) => {
        if (flushed.sent > 0) {
            document.dispatchEvent(new CustomEvent('soundchex:writes-flushed', { detail: flushed }));
        }
    });

    sync.sync().then((result) => {
        // Recorded as well as dispatched. The launch sync is a full one on a
        // device that has never synced, so it redraws the page — and anything
        // arriving afterwards has no way to tell whether that has already
        // happened or is still coming. The event alone cannot answer that.
        window.__soundchexSynced = true;

        // Dispatched rather than logged, so a UI can show "synced" or
        // "offline" without this module knowing anything about the DOM.
        document.dispatchEvent(new CustomEvent('soundchex:synced', { detail: result }));
    });
}

/**
 * Rebuilds the page from the mirror when the server cannot be reached.
 *
 * Only on a page the server never rendered — an offline fallback page, or a
 * navigation that failed. A page already showing real content must be left
 * alone: replacing it would swap live data for a snapshot, which is a
 * downgrade rather than a rescue.
 */
async function considerTakeover() {
    const main = document.querySelector('main');

    // Something already rendered here. Nothing to rescue.
    if (main && main.textContent.trim().length > 40) return;

    if (await serverReachable()) return;

    const drawn = await takeOver();

    document.dispatchEvent(new CustomEvent('soundchex:offline', { detail: { drawn } }));
}

/**
 * Paints the destination from the device the moment a link is tapped.
 *
 * A navigation over the relay costs the best part of a second; the same screen
 * comes out of IndexedDB in single digits. So the local copy is drawn straight
 * away and the server's version replaces it when it arrives — the page is
 * useful immediately instead of after a blank wait.
 *
 * Capture phase, and passive: this must not interfere with the navigation
 * itself, only get ahead of it.
 */
function paintAhead() {
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[href]');

        if (!link || link.origin !== window.location.origin) return;

        // Not the now-playing bar. It is an <a> to the item page, but tapping
        // it opens the full-screen player instead — and repainting <main> here
        // tore out the handler that stops the navigation, so the bar started
        // navigating away again.
        if (link.id === 'np-link') return;

        const main = document.querySelector('main');

        if (!main) return;

        // Only while the destination is one the mirror can actually draw, and
        // only when there is something in it — preRender declines otherwise,
        // so a working page is never replaced by an empty offline screen.
        preRender(main, new URL(link.href).pathname);
    }, true);
}

if (!window.soundchexLibraryBound) {
    window.soundchexLibraryBound = true;

    paintAhead();

    scheduleSync();
    considerTakeover();

    // Watches for the server's address changing underneath the app — a laptop
    // moving between wifi and a hotspot gets a new LAN address, and the one the
    // app is on simply stops answering. Switches to whichever known address
    // responds, so this recovers without the connect screen.
    failover.start();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') scheduleSync();
    });
}

export default library;
