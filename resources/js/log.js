// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Recording what went wrong, from anywhere.
 *
 * The app runs on a phone that is not in the room, so a failure nobody wrote
 * down is a failure reported as "it didn't work". The IndexedDB connection bug
 * was only ever diagnosed because an unhandled rejection happened to be
 * captured — 27 of them stacked behind one `UnknownError` — and downloads
 * themselves recorded nothing at all until it was added deliberately.
 *
 * Kept separate from diagnostics.js so a module can log without importing the
 * panel, the device id and the reporting queue along with it.
 */

/**
 * Records an event, if anything is listening.
 *
 * Never throws. A module that cannot log must still do its job, and a
 * diagnostics failure that breaks a download would be worse than the silence
 * it was meant to fix.
 *
 * @param {string} kind  Specific, and `:failed` by convention when something
 *                       broke — that suffix is what makes it reportable.
 * @param {object} detail Whatever identifies the thing being acted on.
 */
export function log(kind, detail = {}) {
    try {
        window.soundchexDiagnostics?.record?.(kind, detail);
    } catch {
        // Reporting is best-effort by definition.
    }

    // A second, always-on sink that does not depend on the diagnostics module.
    // The connect screen (tauri://) never loads diagnostics, so its storage and
    // download logs went nowhere — the code hardest to see on a device with no
    // console. This ring buffer lives in localStorage on whatever origin is
    // running, and `showLog()` (five-tap top-left) renders it.
    ring(kind, detail);
}

const RING_KEY = 'soundchex.log';
const RING_MAX = 300;

/** Appends one entry to the on-device ring buffer. Never throws. */
function ring(kind, detail) {
    try {
        const entries = JSON.parse(localStorage.getItem(RING_KEY) ?? '[]');

        entries.push({
            kind,
            detail,
            at: Date.now(),
            path: (typeof location !== 'undefined' ? location.pathname : ''),
        });

        // Bounded: keep the most recent, or a long session fills storage.
        while (entries.length > RING_MAX) entries.shift();

        localStorage.setItem(RING_KEY, JSON.stringify(entries));
    } catch {
        // Private browsing, quota, or no localStorage — logging must never
        // break the thing it is watching.
    }
}

/** The on-device log, newest last. For the viewer and for tests. */
export function logEntries() {
    try {
        return JSON.parse(localStorage.getItem(RING_KEY) ?? '[]');
    } catch {
        return [];
    }
}

/** Clears the on-device log. */
export function clearLog() {
    try {
        localStorage.removeItem(RING_KEY);
    } catch {
        // Nothing to clear if storage is unavailable.
    }
}

/**
 * Draws the on-device log over the page — readable on a phone with no console.
 *
 * Self-contained: no diagnostics module, no network, works on the connect
 * screen and inside the app alike. Called from a tap target the pages add.
 */
export function showLog() {
    try {
        document.getElementById('soundchex-log')?.remove();

        const entries = logEntries();
        const panel = document.createElement('div');

        panel.id = 'soundchex-log';
        panel.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:99999',
            'background:#0b0b0f', 'color:#f4f4f5',
            'font:11px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace',
            'padding:1rem', 'overflow:auto', '-webkit-overflow-scrolling:touch',
        ].join(';');

        const rows = entries.slice().reverse().map((e) => {
            const time = new Date(e.at).toLocaleTimeString();
            const detail = escapeHtml(JSON.stringify(e.detail));

            return `<div style="border-top:1px solid #1e1e28;padding:.4rem 0">
                <div style="color:#6d5efc">${time} · ${escapeHtml(e.kind)}</div>
                <div style="color:#9a9aa5;word-break:break-all">${escapeHtml(e.path)}</div>
                <div style="color:#c8c8d0;word-break:break-all">${detail}</div>
            </div>`;
        }).join('');

        panel.innerHTML = `
            <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem">
                <strong style="font-size:13px;flex:1">Log · ${entries.length}</strong>
                <button id="soundchex-log-copy" style="min-height:40px;padding:0 .75rem;border-radius:8px;border:1px solid #2a2a36;background:#1e1e28;color:#f4f4f5">Copy</button>
                <button id="soundchex-log-clear" style="min-height:40px;padding:0 .75rem;border-radius:8px;border:1px solid #2a2a36;background:#1e1e28;color:#f4f4f5">Clear</button>
                <button id="soundchex-log-close" style="min-height:40px;padding:0 .75rem;border-radius:8px;border:1px solid #2a2a36;background:#1e1e28;color:#f4f4f5">Close</button>
            </div>
            ${entries.length === 0 ? '<div style="color:#8b8b96">Nothing logged yet.</div>' : rows}
        `;

        document.body.append(panel);

        panel.querySelector('#soundchex-log-close')?.addEventListener('click', () => panel.remove());
        panel.querySelector('#soundchex-log-clear')?.addEventListener('click', () => {
            clearLog();
            panel.remove();
        });
        panel.querySelector('#soundchex-log-copy')?.addEventListener('click', () => {
            const text = entries.map((e) => `${new Date(e.at).toISOString()} ${e.kind} ${JSON.stringify(e.detail)}`).join('\n');

            navigator.clipboard?.writeText(text).catch(() => {});
        });
    } catch {
        // The viewer failing must not take the page down with it.
    }
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

if (typeof window !== 'undefined') {
    // Reachable from anywhere, including the connect screen and a five-tap
    // gesture, without importing this module.
    window.soundchexShowLog = showLog;

    // A hidden gesture to open the log anywhere in the app, where there is no
    // debug button: five quick taps in the top-left corner. Bounded to that
    // corner and that cadence so it never fires by accident during use.
    try {
        let taps = [];

        window.addEventListener('pointerdown', (event) => {
            if (event.clientX > 80 || event.clientY > 80) {
                taps = [];

                return;
            }

            const now = Date.now();

            taps = taps.filter((t) => now - t < 1500);
            taps.push(now);

            if (taps.length >= 5) {
                taps = [];
                showLog();
            }
        }, { passive: true });
    } catch {
        // No pointer events (a non-DOM context) — the button path still works.
    }
}

/**
 * Records a caught error in a shape that is useful later.
 *
 * `String(error)` alone loses the name, and the name is what separates "the
 * user navigated away" from "the database is gone" — an AbortError is not a
 * fault and should not read like one.
 */
export function logFailure(kind, error, detail = {}) {
    log(kind, {
        ...detail,
        name: error?.name ?? '',
        reason: String(error?.message ?? error).slice(0, 200),
        // Worth separating: an abort is usually the user's own doing.
        aborted: error?.name === 'AbortError',
        // Whether the device thought it had a connection when this happened.
        // navigator.onLine only knows about the interface, which is exactly why
        // it is recorded rather than trusted.
        online: navigator.onLine !== false,
    });
}

/**
 * Wraps a fetch so a failure says which request failed and how.
 *
 * A bare fetch that rejects gives "TypeError: Load failed" with no URL
 * attached, which is what "Could not reach the library" was built on.
 */
export async function loggedFetch(kind, url, options = {}) {
    const started = performance.now();

    try {
        const response = await fetch(url, options);

        if (!response.ok) {
            log(`${kind}:failed`, {
                url: String(url),
                status: response.status,
                ms: Math.round(performance.now() - started),
            });
        }

        return response;
    } catch (error) {
        logFailure(`${kind}:failed`, error, {
            url: String(url),
            ms: Math.round(performance.now() - started),
        });

        throw error;
    }
}
