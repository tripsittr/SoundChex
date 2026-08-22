/**
 * What went wrong, where it can be read on the device.
 *
 * A phone has no console anyone can reach, so a failed navigation shows as a
 * white flash and nothing else — no record of which page was lost, or why. This
 * keeps the last few events and shows them on demand.
 *
 * Off unless asked for: this is a diagnostic, not a feature, and an app that
 * reports its own internals unprompted is noise.
 */
const EVENTS_KEY = 'soundchex.diagnostics';
const MAX_EVENTS = 40;

/** This device, so one phone's reports can be told from another's. */
const DEVICE_KEY = 'soundchex.device-id';

/**
 * Kinds worth telling the server about, unprompted.
 *
 * Anything ending in `:failed` is reported by rule rather than by being listed
 * here — a new failure kind should not have to be remembered in two places.
 * The download logging added earlier recorded faithfully and sent nothing,
 * because nobody thought to add it to this list.
 */
const WORTH_REPORTING = new Set([
    'served-offline-page',
    'switching-address',
    'error',
    'rejection',
]);

/** Whether a kind describes something going wrong. */
function worthReporting(kind) {
    return WORTH_REPORTING.has(kind)
        || kind.endsWith(':failed')
        || kind.endsWith(':error')
        || kind.endsWith(':timeout');
}

/**
 * A random id for this device.
 *
 * Not derived from anything about the person or the hardware: it exists to
 * group one device's reports together, and a value that could identify someone
 * would be a worse thing to store than the problem it helps diagnose.
 */
function deviceId() {
    try {
        const existing = localStorage.getItem(DEVICE_KEY);

        if (existing) return existing;

        const id = (crypto.randomUUID?.() ?? String(Math.random()).slice(2)).slice(0, 36);

        localStorage.setItem(DEVICE_KEY, id);

        return id;
    } catch {
        return 'unknown';
    }
}

/**
 * Sends what has been recorded to the server.
 *
 * Failures are swallowed: this reports problems, and a reporter that throws
 * when the network is down is useless precisely when it is needed. The events
 * stay in session storage either way, so nothing is lost by a send that failed.
 */
/**
 * What the user calls this device.
 *
 * A browser cannot read the device name, and a user agent gives "iPhone" for
 * every iPhone in the house — so it is asked for once, in settings, and stored.
 * Null until then, which is honest: an invented name is worse than none.
 */
function deviceName() {
    return stash('soundchex.device-name');
}

/** Reads a stored value without throwing on private browsing. */
function stash(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

/**
 * The Tauri shell's build, when the app was opened by one.
 *
 * Handed over on the URL by the connect screen, because that screen runs on
 * tauri://localhost and the app on the server's origin — separate localStorage,
 * separate everything. Stashed on arrival so it survives navigation, since the
 * parameter only exists on the first load.
 */
function shellBuild() {
    return carried('shell', 'soundchex.shell-build');
}

/**
 * A value the shell handed over on the URL, stashed so it survives navigation.
 *
 * The parameter only exists on the first load — every page after it is an
 * ordinary navigation — so it has to be kept the moment it arrives.
 */
function carried(param, key) {
    try {
        const fromUrl = new URLSearchParams(window.location.search).get(param);

        if (fromUrl) {
            localStorage.setItem(key, fromUrl);

            return fromUrl;
        }

        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

export async function sendReport() {
    const log = load();

    if (log.length === 0) return { sent: false };

    try {
        const response = await fetch('/api/v1/device-reports', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            // Survives the page going away, which is when the interesting
            // reports are sent: a reload or a failed navigation cancels an
            // ordinary fetch before it leaves, so the one event worth hearing
            // about was the one that never arrived.
            keepalive: true,
            body: JSON.stringify({
                device: deviceId(),
                // Was 40 characters, which cut off before the OS version —
                // "Mozilla/5.0 (iPhone; CPU iPhone OS 18_7" and no further,
                // so the one useful part was the part that was truncated.
                platform: navigator.userAgent.slice(0, 120),
                name: deviceName(),
                // The native app's own version, when there is one. Set by
                // the shell on the way in; a browser has none and says so.
                app_version: carried('app', 'soundchex.app-version'),
                build: window.soundchexBuild?.current?.() ?? null,
                // The shell is a separate thing from the served build and
                // cannot update itself, so a device can be current on one and
                // months behind on the other. Reporting only the served build
                // hides exactly the case where a reinstall is the answer.
                shell: shellBuild(),
                origin: window.location.origin,
                events: log.slice(-MAX_EVENTS),
            }),
        });

        return { sent: response.ok };
    } catch {
        return { sent: false };
    }
}

function load() {
    try {
        const raw = sessionStorage.getItem(EVENTS_KEY);

        return raw ? JSON.parse(raw) : [];
    } catch {
        return [];
    }
}

function save(events) {
    try {
        sessionStorage.setItem(EVENTS_KEY, JSON.stringify(events.slice(-MAX_EVENTS)));
    } catch {
        // Private browsing, or full. Losing diagnostics is not worth an error.
    }
}

export function record(kind, detail = {}) {
    const events = load();

    // Reported without being asked, but only for the kinds that describe
    // something going wrong. Sending every page load would be a stream of
    // noise that buries the one event worth reading.
    if (worthReporting(kind)) {
        // Sent immediately rather than deferred. A timeout does not run if the
        // page is being torn down, which is precisely the case these events
        // describe — the report was scheduled and then discarded with the page
        // that scheduled it.
        sendReport();
    }

    events.push({
        kind,
        detail,
        // Relative to the page load, which is what matters when reading a
        // sequence: "3.2s in" says more than a wall clock time.
        at: Math.round(performance.now()),
        path: window.location.pathname,
    });

    save(events);
}

export function events() {
    return load();
}

/**
 * Draws the log over the page.
 *
 * Deliberately plain and deliberately on top: it is read on a phone, in the
 * moment something has gone wrong, by someone who cannot open a console.
 */
export function showDiagnostics() {
    document.getElementById('soundchex-diagnostics')?.remove();

    const panel = document.createElement('div');

    panel.id = 'soundchex-diagnostics';
    panel.style.cssText = [
        'position:fixed', 'inset:0', 'z-index:9999',
        'background:#0b0b0f', 'color:#f4f4f5',
        'font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace',
        'padding:1rem', 'overflow:auto',
    ].join(';');

    const log = events();

    panel.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem">
            <strong style="font-size:14px">Diagnostics</strong>
            <button id="soundchex-diagnostics-close"
                    style="min-height:44px;padding:0 1rem;border-radius:8px;border:1px solid #2a2a36;background:#1e1e28;color:#f4f4f5">
                Close
            </button>
        </div>
        <div style="color:#8b8b96;margin-bottom:0.75rem">
            ${log.length} event${log.length === 1 ? '' : 's'} ·
            ${navigator.onLine ? 'online' : 'offline'} ·
            ${window.location.origin}
        </div>
        ${log.length === 0
            ? '<div style="color:#8b8b96">Nothing recorded yet.</div>'
            : log.map((event) => `
                <div style="border-top:1px solid #1e1e28;padding:0.5rem 0">
                    <div style="color:#6d5efc">${event.at}ms · ${event.kind}</div>
                    <div style="color:#c8c8d0;word-break:break-all">${event.path}</div>
                    <div style="color:#8b8b96;word-break:break-all">${JSON.stringify(event.detail)}</div>
                </div>
            `).join('')}
    `;

    document.body.append(panel);
    document.getElementById('soundchex-diagnostics-close')
        ?.addEventListener('click', () => panel.remove());
}

/**
 * Starts recording.
 *
 * Listens for what the service worker reports, which is where a lost navigation
 * actually happens — the page only ever sees the result.
 */
export function watchForProblems() {
    if (window.__soundchexDiagnostics) return;

    window.__soundchexDiagnostics = true;

    record('page-load', { referrer: document.referrer || null });

    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.soundchex) {
            record(event.data.soundchex.kind, event.data.soundchex.detail);
        }
    });

    // A page that arrives after the worker reported something still wants to
    // know why it is not the page that was asked for.
    navigator.serviceWorker?.controller?.postMessage({ ask: 'soundchex:events' });

    window.addEventListener('error', (event) => {
        record('error', { message: String(event.message).slice(0, 160) });
    });

    window.addEventListener('unhandledrejection', (event) => {
        record('rejection', { reason: String(event.reason).slice(0, 160) });
    });

    // A last chance as the page goes. Anything recorded and not yet sent —
    // because it was not a reportable kind on its own, or because a send was
    // still in flight — goes now, while there is still a page to send it from.
    window.addEventListener('pagehide', () => {
        if (events().length > 0) sendReport();
    });

    // The worker standing in for a page is the failure being chased, and it is
    // invisible from the page without this.
    if (typeof window.__soundchexWanted === 'string') {
        record('served-offline-page', { wanted: window.__soundchexWanted });
    }
}

window.soundchexDiagnostics = { deviceId, events, record, sendReport, show: showDiagnostics };
