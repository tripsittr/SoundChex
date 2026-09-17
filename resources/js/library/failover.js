// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { log } from '../log.js';

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

/**
 * How much quicker another address has to be before moving to it.
 *
 * Switching means a page load, so it has to be worth one. Addresses within a
 * few milliseconds of each other trade places on measurement noise, and acting
 * on that would reload the app every half minute to no benefit. 250ms is well
 * above the noise and well below the difference that actually hurts — the relay
 * measured 1,300ms slower than a direct route.
 */
const SWITCH_THRESHOLD = 250;

/** Gap while it is not: quicker, because the app is unusable until it returns. */
const SEARCHING_INTERVAL = 5000;

let timer = null;
let switching = false;

/**
 * Consecutive probes the current address has failed.
 *
 * Reset the moment it answers. A phone loses a request to a passing lift or a
 * moment of bad wifi, and treating the first miss as a dead route reloads the
 * app — losing whatever page the user was on.
 */
let missedProbes = 0;

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
 * Asks the server where else it can be reached.
 *
 * Without this the app knows only the address it happened to arrive on, so a
 * device that connected through the public relay had nothing to compare against
 * and stayed there — every page a second and a third slower, for the rest of
 * the session, with no way out short of retyping the address.
 *
 * Merged into the stored list rather than replacing it: an address someone
 * typed by hand is worth keeping even if the server does not know to advertise
 * it.
 */
export async function learnAddresses() {
    // Only meaningful on the server's own origin, where /soundchex-addresses.json
    // exists. On the tauri:// connect screen the fetch resolves to
    // tauri://localhost/… — not a valid URL to fetch — and threw "the string did
    // not match the expected pattern", logged as a failure on every load. The
    // connect screen has its own candidate list and does not need this.
    if (typeof location !== 'undefined' && location.protocol !== 'http:' && location.protocol !== 'https:') {
        return stored();
    }

    try {
        const response = await fetch('/soundchex-addresses.json', { cache: 'no-store' });

        if (!response.ok) return stored();

        const { addresses } = await response.json();

        if (!Array.isArray(addresses) || addresses.length === 0) return stored();

        const merged = [...new Set([...stored(), ...addresses.filter(Boolean)])];

        try {
            localStorage.setItem(CANDIDATES_KEY, JSON.stringify(merged));
        } catch {
            // Private browsing. The addresses are still used for this check.
        }

        return merged;
    } catch (error) {
        // Falls back to whatever was learned before, which is right — but a
        // device that never learns the addresses is a device that stays on
        // whichever route it happened to arrive by, and over the relay that is
        // 843ms a page against 37ms. Silence here looks exactly like a working
        // app that is simply slow.
        log('addresses:failed', {
            reason: String(error?.message ?? error).slice(0, 160),
            known: stored().length,
        });

        return stored();
    }
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
 * Whether an address reaches the server through a public relay.
 *
 * A Tailscale Funnel hostname is served from Tailscale's own infrastructure —
 * the nearest relay here is Los Angeles — so it answers from across the country
 * whatever room the phone is in: 1,332ms against 18ms for the same server on a
 * direct route.
 *
 * It earns its place as the only address that works from outside the tailnet,
 * and it should never be preferred while a direct one answers. Ranked below
 * both, rather than merely slower, because a relay that happens to measure well
 * on one probe is still a relay.
 */
function isRelayed(origin) {
    // The tailnet IP is direct; the public hostname is the Funnel.
    return /\.ts\.net(:|\/|$)/.test(origin);
}

/**
 * The best address that answers, or null when none do.
 *
 * Raced rather than tried in turn: a dead address costs the full timeout, and
 * walking a list of four in sequence would take twelve seconds to find one that
 * was available immediately.
 *
 * Ranked the same way the background re-check ranks: a relay last whatever it
 * measures, then stability, then speed.
 *
 * Relay-last matters most here, because this is the choice made at launch and
 * the app lives with it. The Funnel hostname is a `.ts.net` name, so the
 * stability test says yes to it — and stability outranked speed, which meant a
 * 843ms relay beat a 92ms address on the same Wi-Fi, every single launch. The
 * background check would eventually move off it, but only after the user had
 * already paid a second a page for however long that took.
 *
 * Picking purely on speed is the opposite mistake: it put the app on a LAN
 * address that was momentarily quickest and then stopped existing when the
 * server moved networks. A route that survives the change is worth more than
 * one that shaves a few milliseconds — but not more than one that is forty
 * times quicker.
 */
export async function fastest(origins = candidates(), timeout = PROBE_TIMEOUT) {
    const results = await Promise.all(
        origins.map(async (origin) => ({ origin, ms: await probe(origin, timeout) })),
    );

    const answered = results
        .filter((result) => result.ms !== null)
        .sort((a, b) => (isRelayed(a.origin) - isRelayed(b.origin))
            || (isStable(b.origin) - isStable(a.origin))
            || (a.ms - b.ms));

    return answered[0] ?? null;
}

export { isRelayed, isStable };

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

    // Recorded before it happens: a switch is a full page load, so anything
    // written afterwards is lost with the page that wrote it. Without this the
    // reload is indistinguishable from the app crashing.
    try {
        const events = JSON.parse(sessionStorage.getItem('soundchex.diagnostics') ?? '[]');

        events.push({
            kind: 'switching-address',
            detail: { to: origin, from: window.location.origin },
            at: Math.round(performance.now()),
            path: window.location.pathname,
        });

        sessionStorage.setItem('soundchex.diagnostics', JSON.stringify(events.slice(-40)));
    } catch {
        // Diagnostics are not worth failing a recovery for.
    }

    window.location.replace(origin + window.location.pathname + window.location.search);
}

/**
 * One round: which of the known addresses is best right now?
 *
 * Every candidate is measured, including the one in use. Asking only whether
 * the current address still works is how an app stays on a slow route — the
 * relay answers perfectly well, it just answers from Los Angeles.
 */
export async function check() {
    if (switching) return { status: 'switching' };

    // Offline in the interface sense — no network at all. Nothing to switch to,
    // and probing every candidate would just burn battery.
    if (navigator.onLine === false) return { status: 'offline' };

    const here = window.location.origin;

    // Asked before comparing. The list is otherwise whatever this origin
    // happens to have stored, which on a first connection is nothing at all.
    await learnAddresses();

    const alternatives = candidates().filter((origin) => origin !== here);

    // Everything measured, including where we already are.
    //
    // Returning early on "the current address answers" is how an app gets stuck
    // on a slow route: the public relay answers perfectly well, it just does so
    // from Los Angeles. Measured on this setup the relay took 1,332ms against
    // 18ms direct, and nothing would ever have moved off it because it was
    // never compared against anything.
    const results = await Promise.all(
        [here, ...alternatives].map(async (origin) => ({ origin, ms: await probe(origin) })),
    );

    const answered = results
        .filter((result) => result.ms !== null)
        .sort((a, b) => (isRelayed(a.origin) - isRelayed(b.origin))
            || (isStable(b.origin) - isStable(a.origin))
            || (a.ms - b.ms));

    if (answered.length === 0) return { status: 'unreachable' };

    const best = answered[0];
    const current = results.find((result) => result.origin === here);

    if (best.origin === here) {
        missedProbes = 0;

        return { status: 'healthy', origin: here, ms: best.ms };
    }

    // Only for a difference worth a page reload. Two addresses within a few
    // milliseconds of each other trade places on noise alone, and switching on
    // that would reload the app every half minute for nothing.
    // Leaving a relay is always worth the page load, whatever the margin says.
    // The threshold exists so two comparable addresses do not trade places on
    // noise; a relayed route is not comparable, and staying on one because a
    // single probe looked acceptable is how the app ends up a second and a
    // third slower on every page for the rest of the session.
    const leavingRelay = isRelayed(here) && !isRelayed(best.origin);

    if (! leavingRelay && current?.ms != null && current.ms - best.ms < SWITCH_THRESHOLD) {
        return { status: 'healthy', origin: here, ms: current.ms };
    }

    // A single missed probe is not a dead address.
    //
    // Switching is a full page load — it discards whatever the user was doing
    // and drops them back on the home screen — and a phone drops a request
    // often enough that acting on the first one turns a moment of bad wifi into
    // a lost page. Two in a row is a route that has actually gone.
    if (current?.ms == null) {
        missedProbes += 1;

        if (missedProbes < 2) {
            return { status: 'unsure', origin: here, missed: missedProbes };
        }
    }

    switchTo(best.origin);

    return { status: 'switching', origin: best.origin, ms: best.ms, was: current?.ms ?? null };
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
