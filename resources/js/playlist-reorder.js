// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Drag-to-reorder for playlist tracks.
 *
 * Pointer events rather than HTML5 drag-and-drop, which does not fire on iOS
 * at all — the phone is the main way this app is used, so a desktop-only
 * gesture would be the wrong half to support.
 *
 * The list is reordered optimistically and the new order is sent afterwards.
 * A failed save reloads rather than trying to unwind: the server is the
 * authority on order, and showing a made-up one after a failure is worse than
 * a brief flash of the real thing.
 */
function setupPlaylistReorder() {
    const list = document.querySelector('[data-reorderable]');

    if (!list) return;

    // Rebound per page, but the pointer handlers live on the list itself,
    // which the swap replaces — so there is nothing to guard against stacking.
    let dragging = null;
    let placeholder = null;
    let offsetY = 0;

    function rowFrom(target) {
        return target.closest?.('[data-track-row]') ?? null;
    }

    list.addEventListener('pointerdown', (event) => {
        // Only from the handle. Dragging from anywhere would make it
        // impossible to scroll the list on a touchscreen.
        const handle = event.target.closest('[data-drag-handle]');

        if (!handle) return;

        const row = rowFrom(handle);

        if (!row) return;

        event.preventDefault();

        dragging = row;
        offsetY = event.clientY - row.getBoundingClientRect().top;

        // A placeholder holds the row's space so the list does not collapse
        // and jump under the finger.
        placeholder = document.createElement('li');
        placeholder.style.height = `${row.offsetHeight}px`;
        placeholder.dataset.placeholder = 'true';

        row.after(placeholder);

        row.style.position = 'fixed';
        row.style.zIndex = '60';
        row.style.width = `${row.offsetWidth}px`;
        row.style.top = `${event.clientY - offsetY}px`;
        row.style.pointerEvents = 'none';
        row.classList.add('opacity-90', 'shadow-2xl', 'shadow-black/50');

        handle.setPointerCapture(event.pointerId);
    });

    list.addEventListener('pointermove', (event) => {
        if (!dragging) return;

        event.preventDefault();

        dragging.style.top = `${event.clientY - offsetY}px`;

        // Find the row the pointer is currently over and move the placeholder
        // to whichever side of it the pointer is nearer.
        const rows = [...list.querySelectorAll('[data-track-row]')]
            .filter((row) => row !== dragging);

        for (const row of rows) {
            const box = row.getBoundingClientRect();

            if (event.clientY > box.top && event.clientY < box.bottom) {
                const before = event.clientY < box.top + box.height / 2;

                if (before) {
                    row.before(placeholder);
                } else {
                    row.after(placeholder);
                }

                break;
            }
        }
    });

    async function drop(event) {
        if (!dragging) return;

        const row = dragging;

        dragging = null;

        row.style.position = '';
        row.style.zIndex = '';
        row.style.width = '';
        row.style.top = '';
        row.style.pointerEvents = '';
        row.classList.remove('opacity-90', 'shadow-2xl', 'shadow-black/50');

        placeholder.replaceWith(row);
        placeholder = null;

        renumber();

        const order = [...list.querySelectorAll('[data-track-row]')]
            .map((element) => Number(element.dataset.trackRow));

        try {
            const response = await fetch(list.dataset.reorderUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ order }),
            });

            if (!response.ok) throw new Error(String(response.status));
        } catch {
            // The server did not accept it, so what is on screen is a
            // fiction. Reload rather than leave a lie in place.
            window.location.reload();
        }
    }

    list.addEventListener('pointerup', drop);
    list.addEventListener('pointercancel', drop);

    /** Track numbers are positional, so they are wrong the moment a row moves. */
    function renumber() {
        list.querySelectorAll('[data-track-row]').forEach((row, index) => {
            const number = row.querySelector('[data-track-number]');

            if (number) number.textContent = String(index + 1);
        });
    }
}

document.addEventListener('livewire:navigated', () => setupPlaylistReorder());

setupPlaylistReorder();

export { setupPlaylistReorder };
