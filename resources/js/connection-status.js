/**
 * Telling the user when the connection goes and comes back.
 *
 * The app already copes with being offline — the mirror renders, downloads
 * play, writes queue — but it did all of it silently. Nothing announced the
 * change, so a page that came from the device looked identical to one that came
 * from the server, and the moment something did not work there was no way to
 * tell whether the app was broken or the network was.
 *
 * Deliberately not a banner. A permanent bar costs screen space on a phone for
 * a state that is usually temporary, and the offline shell already says so on
 * the screens it draws.
 */

/** How long a toast stays up. Long enough to read, short enough not to nag. */
const DWELL = 3200;

let host = null;
let timer = null;

function show(message, tone) {
    host ??= document.getElementById('soundchex-toast');

    if (!host) {
        host = document.createElement('div');
        host.id = 'soundchex-toast';
        host.className = 'toast';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.append(host);
    }

    host.textContent = message;
    host.dataset.tone = tone;
    host.dataset.visible = 'true';

    clearTimeout(timer);
    timer = setTimeout(() => { host.dataset.visible = 'false'; }, DWELL);
}

/**
 * Whether the server is actually reachable, rather than whether an interface
 * exists.
 *
 * `navigator.onLine` reports on the network interface: it is true on a wifi
 * network with no route out, which on a phone is common enough — a captive
 * portal, a tunnel, a router that is up but not connected. Asking the server
 * directly is the only answer worth acting on.
 */
async function serverAnswers(timeout = 3000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch('/soundchex.json', {
            signal: controller.signal,
            cache: 'no-store',
            credentials: 'omit',
        });

        return response.ok;
    } catch {
        return false;
    } finally {
        clearTimeout(timer);
    }
}

export function watchConnection() {
    if (window.__soundchexConnectionBound) return;

    window.__soundchexConnectionBound = true;

    // Tracked rather than read from navigator each time, so the toast fires on
    // a real change instead of on every event the browser feels like sending.
    let online = navigator.onLine !== false;

    const settle = async (assume) => {
        // The interface is a hint, not an answer: confirm before announcing.
        const reachable = assume === false ? false : await serverAnswers();

        if (reachable === online) return;

        online = reachable;

        // Published as well as announced. The events say when it *changed*;
        // anything arriving later — a page from an SPA swap, rows the offline
        // shell rebuilt — needs to know what the state *is*, and
        // `navigator.onLine` cannot answer that (it knows about the interface,
        // not whether anything is reachable through it).
        window.soundchexOffline = !reachable;

        if (reachable) {
            show('Back online', 'ok');
            document.dispatchEvent(new CustomEvent('soundchex:back-online'));
        } else {
            show('Offline — playing what is on this device', 'warn');
            document.dispatchEvent(new CustomEvent('soundchex:went-offline'));
        }
    };

    // The interface noticing is the fastest signal there is, and going down is
    // the one case it is reliable about: no interface means no route.
    window.addEventListener('offline', () => settle(false));
    window.addEventListener('online', () => settle(true));

    // Returning to the app after it was backgrounded, which on a phone is when
    // the connection most often changed without an event firing at all.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') settle(true);
    });

    // A request that failed because the server was unreachable is the most
    // direct evidence there is, and it arrives before any event does.
    document.addEventListener('soundchex:request-failed', () => settle(false));
}

watchConnection();
