// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * What is downloading, in the account menu.
 *
 * "Added to download queue" and then nothing is indistinguishable from a button
 * that did nothing — especially for a whole library, where the first file can
 * take a while and the toast has long gone. This says how many are left and,
 * when asked, which ones.
 *
 * Shown only while something is queued: a permanent empty row is clutter.
 */
import * as queue from './download-queue.js';

/** Counted across a batch so the title can say 12/40 rather than "28 left". */
let started = 0;
let finished = 0;

function reset() {
    started = 0;
    finished = 0;
}

function render() {
    const container = document.querySelector('[data-download-queue]');

    if (!container) return;

    const remaining = queue.size();

    if (remaining === 0) {
        container.hidden = true;

        // Collapsed as well as hidden, so the next batch does not open showing
        // the last one's leftovers.
        const list = container.querySelector('[data-download-queue-list]');

        if (list) list.hidden = true;

        reset();

        return;
    }

    container.hidden = false;

    const count = container.querySelector('[data-download-queue-count]');

    if (count) {
        // Out of the whole batch, not the part still waiting: "3 of 40" says
        // how far along this is, where "37 left" says only that it is not done.
        count.textContent = started > 0
            ? `${finished} of ${started}`
            : String(remaining);
    }

    const list = container.querySelector('[data-download-queue-list]');

    if (!list || list.hidden) return;

    const active = queue.current();
    const waiting = queue.pending();

    list.replaceChildren(...[
        ...(active ? [row(active.title, true)] : []),
        ...waiting.slice(0, 20).map((item) => row(item.title, false)),
        ...(waiting.length > 20
            ? [row(`and ${waiting.length - 20} more`, false, true)]
            : []),
    ]);
}

function row(title, isActive, muted = false) {
    const line = document.createElement('div');

    line.className = [
        'truncate py-1 text-xs',
        isActive ? 'text-ink-100' : 'text-ink-500',
        muted ? 'italic' : '',
    ].join(' ').trim();

    line.textContent = isActive ? `${title} — downloading` : title;

    return line;
}

export function bindDownloadQueueUi() {
    if (window.__soundchexQueueUi) return;

    window.__soundchexQueueUi = true;

    // Delegated, because the menu is re-rendered on every navigation.
    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-download-queue-toggle]');

        if (!toggle) return;

        event.preventDefault();
        event.stopPropagation();

        const list = document.querySelector('[data-download-queue-list]');

        if (!list) return;

        list.hidden = !list.hidden;
        render();
    });

    document.addEventListener('soundchex:download-queued', () => {
        started += 1;
        render();
    });

    document.addEventListener('soundchex:download-started', render);

    document.addEventListener('soundchex:download-finished', () => {
        finished += 1;
        render();
    });

    document.addEventListener('soundchex:download-failed', () => {
        // Counted as done: it is no longer waiting, and a total that never
        // reaches its target reads as the queue being stuck.
        finished += 1;
        render();
    });

    document.addEventListener('soundchex:download-idle', render);

    render();
}

window.soundchexQueueUi = { render };
