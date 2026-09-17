// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { list } from './downloads.js';

/**
 * "Downloaded" as a filter, and as the reason a row is dimmed offline.
 *
 * Both answer the same question — is this file on this device? — and the answer
 * lives in IndexedDB, which the server has never been told about. So this runs
 * in the browser rather than as a query string: a `?downloaded=1` the server
 * cannot honour would be a filter that silently does nothing.
 *
 * Being client-side is also what makes it work with nothing reachable, which is
 * when knowing what you can actually play matters most.
 */

/** Remembered per device, so the choice survives a navigation and a launch. */
const KEY = 'soundchex.filter.downloaded';

/**
 * Rows whose file is not on this device, while there is no server.
 *
 * Dimmed rather than hidden: the metadata is worth reading even when the audio
 * is not there — knowing the album exists, and that this is the track you were
 * looking for, is most of what a catalogue is for. Hiding it would make the
 * library look like it had shrunk.
 */
const DIMMED = 'is-unavailable-offline';

function enabled() {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        // Private browsing, or storage denied. The filter simply does not
        // persist; it still works for this page.
        return false;
    }
}

function remember(on) {
    try {
        if (on) localStorage.setItem(KEY, '1');
        else localStorage.removeItem(KEY);
    } catch {
        // See above — not worth reporting, and not worth failing over.
    }
}

/** Every row that carries an item id, whoever drew it. */
function rows() {
    return document.querySelectorAll('li[data-long-press-menu]');
}

/**
 * The item a row is about.
 *
 * Read from the download button rather than the row, because that is where the
 * id already is — the offline shell and Blade both put it there, so this works
 * on rows from either.
 */
function idOf(row) {
    return row.querySelector('[data-download]')?.dataset.download ?? null;
}

/**
 * Applies the filter and the offline dimming to what is on screen.
 *
 * Idempotent, and safe to call on a page with no rows: it is bound once and
 * runs again on every navigation, every sync and every download.
 */
export async function applyDownloadedFilter() {
    const all = [...rows()];

    if (all.length === 0) return;

    const held = new Set((await list()).map((entry) => String(entry.id)));
    const filtering = enabled();

    // `navigator.onLine` knows about the interface rather than whether
    // anything is reachable through it, so it over-reports being online. The
    // connection watcher knows better when it has looked; fall back to the
    // browser's own answer when it has not.
    const offline = window.soundchexOffline === true || navigator.onLine === false;

    for (const row of all) {
        const id = idOf(row);
        const downloaded = id !== null && held.has(String(id));

        row.hidden = filtering && !downloaded;
        row.classList.toggle(DIMMED, offline && !downloaded);
    }

    document.dispatchEvent(new CustomEvent('soundchex:downloaded-filter', {
        detail: { filtering, offline, held: held.size, rows: all.length },
    }));
}

/**
 * Binds the checkbox and keeps the view in step.
 *
 * Delegated on `document` and guarded, because rows and the checkbox itself
 * arrive with SPA navigation and are rebuilt by the offline shell — anything
 * bound to a specific element goes stale on the next page.
 */
export function setupDownloadedFilter() {
    const box = document.querySelector('[data-filter-downloaded]');

    if (box) box.checked = enabled();

    applyDownloadedFilter();

    if (window.__soundchexDownloadedFilterBound) return;

    window.__soundchexDownloadedFilterBound = true;

    document.addEventListener('change', (event) => {
        const target = event.target.closest('[data-filter-downloaded]');

        if (!target) return;

        remember(target.checked);
        applyDownloadedFilter();
    });

    // Re-applied whenever what is on the device, or the connection, changes.
    // A track downloaded while the filter is on should appear without a
    // reload, and a row dimmed offline should brighten when the server
    // returns.
    const triggers = [
        'soundchex:downloads-painted',
        'soundchex:page-refreshed',
        // The connection watcher's own verdict, which is better than
        // `navigator.onLine` — it has actually asked the server.
        'soundchex:went-offline',
        'soundchex:back-online',
    ];

    for (const name of triggers) {
        document.addEventListener(name, () => applyDownloadedFilter());
    }

    window.addEventListener('online', () => applyDownloadedFilter());
    window.addEventListener('offline', () => applyDownloadedFilter());
}
