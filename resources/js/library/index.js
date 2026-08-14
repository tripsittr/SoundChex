import * as mirror from './mirror.js';
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
    sync: sync.sync,
    signOut: sync.signOut,
    setToken: sync.setToken,
    token: sync.token,
    resyncForProfile: sync.resyncForProfile,

    /** Everything the device holds, for a caller that wants to query it. */
    all: mirror.all,
};

window.soundchexLibrary = library;

/**
 * Syncs when the app is opened and whenever it comes back to the foreground.
 *
 * Foreground matters more than it looks: a phone suspends the page rather than
 * closing it, so an app left open for a day would otherwise show a day-old
 * library with no indication anything was stale.
 */
function scheduleSync() {
    if (!sync.token()) return;

    sync.sync().then((result) => {
        // Dispatched rather than logged, so a UI can show "synced" or
        // "offline" without this module knowing anything about the DOM.
        document.dispatchEvent(new CustomEvent('soundchex:synced', { detail: result }));
    });
}

if (!window.soundchexLibraryBound) {
    window.soundchexLibraryBound = true;

    scheduleSync();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') scheduleSync();
    });
}

export default library;
