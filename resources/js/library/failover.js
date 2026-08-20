/**
 * Staying connected when the server's address changes underneath the app.
 *
 * A self-hosted server is reachable several ways at once — LAN, Tailscale, a
 * public tunnel — and the LAN one is only true until the machine joins a
 * different network. Moving a laptop between wifi and a hotspot changes it, and
 * the app was left pointed at an address that no longer resolved: every request
 * hung until it timed out, which reads as the server being down when it is
 * running perfectly well a few feet away.
 *
 * The connect screen already races the known addresses at launch. This does the
 * same thing during the session, when the current one stops answering.
 */
const CANDIDATES_KEY = 'soundchex.hosts';

/** How long a probe waits before calling an address dead. */
const PROBE_TIMEOUT = 3000;

/** Gap between checks while the server is answering. */
const HEALTHY_INTERVAL = 30000;

/** Gap while it is not: quicker, because the app is unusable until it returns. */
const SEARCHING_INTERVAL = 5000;

let timer = null;
let switching = false;

function stored() {
    try {
        const raw = localStorage.getItem(CANDIDATES_KEY);
        const list = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(list) ? list.filter(Boolean) : [];
    } catch {
        return [];
    }
}

/**
 * Every address worth trying, current origin first.
 *
 * The origin is included because it is demonstrably reachable — this page came
 * from it — and it may not be in the stored list at all.
 */
export function candidates() {
    return [...new Set([window.location.origin, ...stored()])];
}

/**
 * Asks the server, from this device, whether an address answers.
 *
 * An unauthenticated identity endpoint rather than a real route: the question
 * is whether *this* server is there, and a 401 from a valid host would be
 * indistinguishable from a timeout if the check could fail on credentials.
 */
export async function probe(origin, timeout = PROBE_TIMEOUT) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const started = performance.now();

    try {
        // Identified, not merely answered. A no-cors probe resolves for any
        // response — a captive portal, a router page, an unrelated server — so
        // the app would switch to whatever replied fastest regardless of what
        // it was.
        const response = await fetch(`${origin}/soundchex.json`, {
            signal: controller.signal,
            credentials: 'omit',
            cache: 'no-store',
        });

        if (!response.ok) return null;

        const body = await response.json();

        if (body?.app !== 'soundchex') return null;

        return Math.round(performance.now() - started);
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
    }
}

/**
 * Whether an address survives the server changing networks.
 *
 * A Tailscale address — 100.64.0.0/10, or a MagicDNS name — is assigned by the
 * tailnet and follows the machine wherever it goes. A LAN address is handed out
 * by whatever router the server is currently on, so it is only true until it
 * joins a different network.
 */
function isStable(origin) {
    return /^https?:\/\/100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./.test(origin)
        || /\.ts\.net(:|\/|$)/.test(origin);
}

/**
 * The best address that answers, or null when none do.
 *
 * Raced rather than tried in turn: a dead address costs the full timeout, and
 * walking a list of four in sequence would take twelve seconds to find one that
 * was available immediately.
 *
 * Ranked by stability first and speed second. Picking purely on speed is what
 * put the app on a LAN address that was momentarily quickest and then stopped
 * existing when the server moved networks — a route that survives the change is
 * worth more than one that shaves a few milliseconds off each request.
 */
export async function fastest(origins = candidates(), timeout = PROBE_TIMEOUT) {
    const results = await Promise.all(
        origins.map(async (origin) => ({ origin, ms: await probe(origin, timeout) })),
    );

    const answered = results
        .filter((result) => result.ms !== null)
        .sort((a, b) => (isStable(b.origin) - isStable(a.origin)) || (a.ms - b.ms));

    return answered[0] ?? null;
}

export { isStable };

/**
 * Moves the app to another address.
 *
 * A full load rather than a rewrite of in-flight requests: the origin is part
 * of every URL on the page, the session cookie is scoped to it, and the audio
 * element holds a src pointing at the old host. Carrying the current path over
 * so the user lands where they were.
 */
function switchTo(origin) {
    if (origin === window.location.origin) return;

    switching = true;
    window.location.replace(origin + window.location.pathname + window.location.search);
}

/**
 * One round: is the current address still good, and if not, is another?
 */
export async function check() {
    if (switching) return { status: 'switching' };

    // Offline in the interface sense — no network at all. Nothing to switch to,
    // and probing every candidate would just burn battery.
    if (navigator.onLine === false) return { status: 'offline' };

    if (await probe(window.location.origin) !== null) {
        return { status: 'healthy', origin: window.location.origin };
    }

    const alternatives = candidates().filter((origin) => origin !== window.location.origin);

    if (alternatives.length === 0) return { status: 'unreachable' };

    const winner = await fastest(alternatives);

    if (winner === null) return { status: 'unreachable' };

    switchTo(winner.origin);

    return { status: 'switching', origin: winner.origin, ms: winner.ms };
}

/**
 * Keeps checking for as long as the app is open.
 *
 * Paused while the tab is hidden: a backgrounded phone app should not be
 * probing the network, and the visibility change on return triggers an
 * immediate check anyway — which is the moment it matters, since that is when
 * the network is most likely to have changed.
 */
export function start() {
    if (timer !== null) return;

    const tick = async () => {
        const result = await check();

        timer = setTimeout(tick, result.status === 'healthy'
            ? HEALTHY_INTERVAL
            : SEARCHING_INTERVAL);
    };

    timer = setTimeout(tick, HEALTHY_INTERVAL);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') check();
    });

    // The interface noticing before a probe would is worth acting on: this is
    // the case where a phone rejoins wifi and the app can recover immediately
    // rather than waiting out the interval.
    window.addEventListener('online', () => check());
}

export function stop() {
    clearTimeout(timer);
    timer = null;
}
