import { formatBytes, list, remove, storageEstimate } from './downloads.js';

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

    if (!window.indexedDB) {
        unsupported?.classList.remove('hidden');
    } else {
        render();
    }

    async function render() {
        // list() prunes records whose blob the browser reclaimed, so what is
        // shown is always something that can actually be played.
        const rows = await list();

        root.replaceChildren();

        if (rows.length === 0) {
            empty?.classList.remove('hidden');
            summary?.classList.add('hidden');

            return;
        }

        empty?.classList.add('hidden');

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
            await remove(row.id);
            await render();
        });

        wrapper.append(link, button);

        return wrapper;
    }
}
