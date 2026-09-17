// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { list, remove } from './offline/storage.js';
import { formatBytes, storageEstimate } from './downloads.js';

/**
 * The offline downloads screen.
 *
 * Rendered client-side because the server cannot know what any given device
 * holds — two phones signed into the same account carry different files.
 */
const root = document.getElementById('downloads-list');

if (root) {
    const summary = document.getElementById('downloads-summary');
    const empty = document.getElementById('downloads-empty');
    const unsupported = document.getElementById('downloads-unsupported');
    const actions = document.getElementById('downloads-actions');
    const confirmDialog = document.getElementById('downloads-confirm');

    if (!window.indexedDB) {
        unsupported?.classList.remove('hidden');
    } else {
        render();
    }

    bindRemoveAll();

    /**
     * Removing everything, behind a confirmation.
     *
     * Irreversible and easy to hit by accident on a phone, so it names what it
     * is about to delete and how much space it frees before doing it.
     */
    function bindRemoveAll() {
        const trigger = document.getElementById('downloads-remove-all');
        const cancel = document.getElementById('downloads-confirm-cancel');
        const accept = document.getElementById('downloads-confirm-remove');
        const detail = document.getElementById('downloads-confirm-detail');

        if (!trigger || !confirmDialog || !accept) return;

        const close = () => {
            confirmDialog.classList.add('hidden');
            confirmDialog.classList.remove('flex');
        };

        trigger.addEventListener('click', async () => {
            const rows = await list();

            if (rows.length === 0) return;

            const bytes = rows.reduce((sum, row) => sum + (row.size ?? 0), 0);

            if (detail) {
                detail.textContent = `${rows.length} ${rows.length === 1 ? 'file' : 'files'}, `
                    + `${formatBytes(bytes)}, will be deleted from this device.`;
            }

            confirmDialog.classList.remove('hidden');
            confirmDialog.classList.add('flex');
        });

        cancel?.addEventListener('click', close);

        // The backdrop, but not the panel itself.
        confirmDialog.addEventListener('click', (event) => {
            if (event.target === confirmDialog) close();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !confirmDialog.classList.contains('hidden')) close();
        });

        accept.addEventListener('click', async () => {
            accept.disabled = true;
            accept.textContent = 'Removing…';

            const rows = await list();
            let failed = 0;

            for (const row of rows) {
                try {
                    // eslint-disable-next-line no-await-in-loop
                    await remove(row.id);
                } catch {
                    // One file refusing to go must not strand the rest.
                    failed++;
                }
            }

            window.soundchexDiagnostics?.record?.('download:remove-all', {
                requested: rows.length,
                failed,
            });

            accept.disabled = false;
            accept.textContent = 'Remove all';
            close();

            await render();
        });
    }

    async function render() {
        // list() prunes records whose blob the browser reclaimed, so what is
        // shown is always something that can actually be played.
        const rows = await list();

        root.replaceChildren();

        if (rows.length === 0) {
            empty?.classList.remove('hidden');
            summary?.classList.add('hidden');
            actions?.classList.add('hidden');
            actions?.classList.remove('flex');

            return;
        }

        empty?.classList.add('hidden');
        actions?.classList.remove('hidden');
        actions?.classList.add('flex');

        await renderSummary(rows);

        rows.forEach((row) => root.append(buildRow(row)));
    }

    async function renderSummary(rows) {
        if (!summary) return;

        const used = rows.reduce((sum, row) => sum + (row.size ?? 0), 0);
        const estimate = await storageEstimate();

        summary.textContent = estimate?.quota
            ? `${rows.length} ${rows.length === 1 ? 'item' : 'items'} · `
              + `${formatBytes(used)} used · ${formatBytes(estimate.free)} free on this device`
            : `${rows.length} ${rows.length === 1 ? 'item' : 'items'} · ${formatBytes(used)}`;

        summary.classList.remove('hidden');
    }

    function buildRow(row) {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center gap-4 rounded-lg border border-base-600 px-4 py-3';

        const link = document.createElement('a');
        link.href = `/app/item/${row.id}`;
        link.className = 'min-w-0 flex-1';

        const title = document.createElement('p');
        title.className = 'truncate text-sm font-medium text-ink-100';
        title.textContent = row.title ?? 'Untitled';

        const meta = document.createElement('p');
        meta.className = 'text-xs text-ink-500';
        meta.textContent = [row.type, formatBytes(row.size ?? 0)].filter(Boolean).join(' · ');

        link.append(title, meta);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'shrink-0 rounded-md border border-base-500 px-3 py-1.5 text-xs text-ink-300 transition hover:border-red-500 hover:text-red-300';
        button.textContent = 'Remove';
        button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Removing…';

            try {
                await remove(row.id);
                await render();
            } catch (error) {
                // A failed remove used to leave a disabled button and no
                // explanation, which reads exactly like a button that does
                // nothing — the reported symptom.
                window.soundchexDiagnostics?.record?.('download:remove-failed', {
                    id: String(row.id),
                    reason: String(error?.message ?? error).slice(0, 160),
                });

                button.disabled = false;
                button.textContent = 'Retry';
                button.classList.add('border-red-500', 'text-red-300');
            }
        });

        wrapper.append(link, button);

        return wrapper;
    }
}
