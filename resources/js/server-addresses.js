/**
 * Managing the addresses this app can reach the server on.
 *
 * The connect screen sets these once at first launch and then redirects past
 * itself forever, so without this there is no way to add or correct an address
 * short of deleting the app. That matters more than it sounds: the difference
 * between the fastest and slowest route to the same server was measured at
 * 22ms against 782ms, and the app was stuck on whichever one was typed first.
 *
 * Lives on the *server* origin rather than in the connect screen, so it is
 * reachable from inside the running app. The two origins have separate
 * localStorage, so the values are mirrored across via the shell — see the
 * comment on persist().
 */
const KEY = 'soundchex.host';
const CANDIDATES_KEY = 'soundchex.hosts';

function read(key, fallback) {
    try {
        const raw = localStorage.getItem(key);

        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function addresses() {
    const stored = read(CANDIDATES_KEY, []);
    const list = Array.isArray(stored) ? stored.filter(Boolean) : [];

    // The current origin is always a candidate: it is demonstrably reachable,
    // since this page came from it.
    return [...new Set([window.location.origin, ...list])];
}

/**
 * Stores the list on both origins.
 *
 * The connect screen runs from tauri:// and the app from the server's origin,
 * and localStorage is per-origin — so a list written here is invisible to the
 * screen that actually uses it. The shell reads this back through a bridge on
 * launch; until then the copy written here is what a later visit to the app
 * sees, and the bridged copy is what the connect screen races.
 */
function persist(list) {
    const unique = [...new Set(list.filter(Boolean))];

    try {
        localStorage.setItem(CANDIDATES_KEY, JSON.stringify(unique));
    } catch {
        // Private browsing. Nothing to do but carry on.
    }

    // Handed to the shell, which stores it on its own origin where the
    // connect screen can read it.
    window.__TAURI__?.core?.invoke?.('store_addresses', { addresses: unique })
        .catch(() => {});

    return unique;
}

export function addAddress(value) {
    const origin = normalise(value);

    if (origin === null) return null;

    persist([...addresses(), origin]);

    return origin;
}

export function removeAddress(origin) {
    persist(addresses().filter((candidate) => candidate !== origin));
}

/**
 * Accepts what a private address actually looks like.
 *
 * A LAN or VPN host has no certificate and needs none; requiring https would
 * rule out the only fast route to the server.
 */
export function normalise(value) {
    const trimmed = String(value ?? '').trim();

    if (trimmed === '') return null;

    const isPrivate = /^(https?:\/\/)?(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.)/
        .test(trimmed);

    const withScheme = /^https?:\/\//i.test(trimmed)
        ? trimmed
        : `${isPrivate ? 'http' : 'https'}://${trimmed}`;

    try {
        const url = new URL(withScheme);

        return url.protocol === 'https:' || isPrivate ? url.origin : null;
    } catch {
        return null;
    }
}

/**
 * How long each address takes to answer.
 *
 * Measured rather than assumed, because the answer changes with the network:
 * the LAN address is the fastest at home and unreachable on mobile data.
 */
export async function measure(origin, timeout = 4000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const started = performance.now();

    try {
        await fetch(`${origin}/login`, {
            mode: 'no-cors',
            signal: controller.signal,
            credentials: 'omit',
            cache: 'no-store',
        });

        return Math.round(performance.now() - started);
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
    }
}

export async function measureAll() {
    const list = addresses();

    return Promise.all(list.map(async (origin) => ({
        origin,
        ms: await measure(origin),
        active: origin === window.location.origin,
    })));
}

window.soundchexAddresses = {
    addresses,
    addAddress,
    removeAddress,
    measure,
    measureAll,
    normalise,
};
