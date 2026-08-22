/**
 * Bringing a page that is already open up to date.
 *
 * The mirror syncs on launch and whenever the app returns to the foreground,
 * and a fresh navigation always queries the server. What neither covers is the
 * screen someone is currently looking at: a scan that imports a hundred tracks
 * while the songs list is open leaves that list exactly as it was, with no
 * indication anything is missing.
 *
 * Reloading outright would be wrong — it would interrupt playback setup, lose
 * scroll position and re-run every module on the page. Only the part that shows
 * items is replaced.
 */

/**
 * How long after a sync to wait before redrawing.
 *
 * A scan imports in bursts, and several deltas can land within a second or two
 * of each other. Redrawing on each one would flicker the list repeatedly for
 * what is, to the user, a single event.
 */
const SETTLE = 1200;

/** Screens whose contents come from the library and can safely be replaced. */
const REFRESHABLE = /^\/app(\/(music|movie|show|book|albums|artists|playlists)?)?\/?$/;

let pending = null;

/**
 * Replaces the item-bearing part of the page from the server.
 *
 * A fetch of the same URL rather than location.reload(): the response is the
 * page as it stands now, and taking only <main> from it leaves the player, the
 * now-playing bar and every bound handler outside it untouched.
 */
async function redraw() {
    const main = document.querySelector('main');

    if (!main) return false;

    try {
        const response = await fetch(window.location.href, {
            headers: { 'X-Requested-With': 'soundchex-refresh' },
            cache: 'no-store',
        });

        if (!response.ok) return false;

        const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
        const incoming = parsed.querySelector('main');

        if (!incoming) return false;

        // Scroll position is the user's place in a list of thousands, and
        // losing it is more disruptive than the stale row it was fixing.
        const scrollY = window.scrollY;

        main.replaceChildren(...incoming.childNodes);

        window.scrollTo(0, scrollY);

        // The rows are new elements, so anything that decorates them has to run
        // again — download state most of all, or a stored track comes back
        // showing an empty download icon.
        document.dispatchEvent(new CustomEvent('soundchex:repaint-downloads'));
        document.dispatchEvent(new CustomEvent('soundchex:page-refreshed'));

        return true;
    } catch (error) {
        window.soundchexDiagnostics?.record?.('library-refresh:failed', {
            reason: String(error?.message ?? error).slice(0, 160),
        });

        return false;
    }
}

function schedule() {
    // Not while the tab is in the background: the foreground handler syncs on
    // return anyway, and redrawing a page nobody is looking at spends a request
    // on a phone for nothing.
    if (document.visibilityState !== 'visible') return;

    if (!REFRESHABLE.test(window.location.pathname)) return;

    clearTimeout(pending);
    pending = setTimeout(redraw, SETTLE);
}

export function watchForNewItems() {
    if (window.__soundchexRefreshBound) return;

    window.__soundchexRefreshBound = true;

    document.addEventListener('soundchex:synced', (event) => {
        const { updated = 0, removed = 0, full = false } = event.detail ?? {};

        // Only when the library actually moved. A sync that found nothing is
        // the common case and must not cost a redraw.
        if (updated === 0 && removed === 0 && !full) return;

        schedule();
    });
}

watchForNewItems();
