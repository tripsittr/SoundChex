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

    // The worker standing in for a page is the failure being chased, and it is
    // invisible from the page without this.
    if (typeof window.__soundchexWanted === 'string') {
        record('served-offline-page', { wanted: window.__soundchexWanted });
    }
}

window.soundchexDiagnostics = { events, record, show: showDiagnostics };
