// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { logFailure } from './log.js';

/**
 * Adding tracks to a playlist.
 *
 * Delegated on `document` and bound once, because the controls appear on rows
 * that are rendered per page and inside an SPA-swapped body — binding per
 * element would miss anything added later and stack handlers on every
 * navigation.
 */
function setupPlaylists() {
    if (window.soundchexPlaylistsBound) return;

    window.soundchexPlaylistsBound = true;

    // Play / play next / add to queue, read from the menu's own dataset so the
    // markup carries the payload and the script needs no route helpers.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-menu-play], [data-menu-play-next], [data-menu-queue]');

        if (!button) return;

        event.preventDefault();

        const menu = button.closest('.track-menu');
        const player = window.soundchexPlayer;

        if (!menu || !player) return;

        let items = [];

        try {
            items = JSON.parse(menu.dataset.queue ?? '[]');
        } catch {
            return;
        }

        if (items.length === 0) return;

        if (button.hasAttribute('data-menu-play')) {
            player.play(items, 0);
        } else if (button.hasAttribute('data-menu-play-next')) {
            player.playNext(items);
        } else {
            player.enqueue(items);
        }

        menu.removeAttribute('open');
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-add-to-playlist]');

        if (!button) return;

        event.preventDefault();

        const holder = button.closest('.add-to-playlist, .track-menu');
        const playlistId = button.dataset.addToPlaylist;

        let items = [];

        try {
            items = JSON.parse(holder?.dataset.items ?? '[]');
        } catch {
            return;
        }

        if (items.length === 0) return;

        const original = button.textContent;

        button.disabled = true;
        button.textContent = 'Adding…';

        try {
            // One request per track. The endpoint appends, so order is
            // preserved, and a partial failure leaves the tracks that did
            // succeed in place rather than rolling the lot back.
            for (const id of items) {
                const response = await fetch(`/app/playlists/${playlistId}/items`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ item_id: id }),
                });

                if (!response.ok) throw new Error(String(response.status));
            }

            button.textContent = items.length > 1 ? `Added ${items.length}` : 'Added';

            // Closing on success is the confirmation; leaving it open invites
            // a second click that would silently duplicate nothing useful.
            setTimeout(() => {
                holder?.removeAttribute('open');
                button.textContent = original;
                button.disabled = false;
            }, 900);
        } catch (error) {
            // "Failed" and nothing else: which playlist, which tracks, and
            // whether the server said no or the request never arrived were all
            // discarded.
            logFailure('playlist:add:failed', error, { tracks: items.length });

            button.textContent = 'Failed';
            setTimeout(() => {
                button.textContent = original;
                button.disabled = false;
            }, 1500);
        }
    });

    // Shuffle the whole library. The queue is fetched rather than embedded,
    // because putting every track in the page to pick from is exactly what
    // the endpoint exists to avoid.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-shuffle-library]');

        if (!button) return;

        event.preventDefault();

        const player = window.soundchexPlayer;

        if (!player) return;

        const label = button.querySelector('[data-shuffle-label]');
        const original = label?.textContent;

        button.disabled = true;

        if (label) label.textContent = 'Shuffling…';

        try {
            const response = await fetch('/app/shuffle', { headers: { Accept: 'application/json' } });

            if (!response.ok) throw new Error(String(response.status));

            const { queue } = await response.json();

            if (!queue?.length) throw new Error('empty');

            // Shuffle is already applied server-side, so the player's own
            // shuffle stays off — turning it on would reshuffle a shuffled
            // list and make "next" unpredictable for no gain.
            player.play(queue, 0);
        } catch (error) {
            logFailure('shuffle:failed', error);

            if (label) label.textContent = 'Could not shuffle';
        } finally {
            setTimeout(() => {
                if (label) label.textContent = original;
                button.disabled = false;
            }, 1200);
        }
    });

    // Long-press opens the menu on a touchscreen.
    //
    // A kebab that only appears on hover is unreachable with a finger, and
    // shrinking it to a permanent tap target would clutter every row. Pressing
    // and holding is the phone convention for "more options".
    let pressTimer = null;
    let pressTarget = null;

    const cancelPress = () => {
        clearTimeout(pressTimer);
        pressTimer = null;
        pressTarget = null;
    };

    document.addEventListener('touchstart', (event) => {
        const row = event.target.closest('[data-long-press-menu]');

        if (!row) return;

        // Not on a control: a long press on the play button means the user is
        // hesitating over play, not asking for a menu.
        if (event.target.closest('button, a, summary, input')) return;

        pressTarget = row;

        pressTimer = setTimeout(() => {
            const menu = row.querySelector('.track-menu');

            if (!menu) return;

            menu.setAttribute('open', '');

            // The same feedback a native long press gives, where supported.
            navigator.vibrate?.(15);
        }, 450);
    }, { passive: true });

    // Any of these means the press ended or turned into a scroll.
    ['touchend', 'touchmove', 'touchcancel', 'scroll'].forEach((name) => {
        document.addEventListener(name, cancelPress, { passive: true });
    });

    // Opening a menu by long press must not also fire the click that follows,
    // which would immediately close it again.
    document.addEventListener('click', (event) => {
        if (pressTarget && event.target.closest('[data-long-press-menu]') === pressTarget) {
            cancelPress();
        }
    }, true);

    // The now-playing sheet asks for this rather than carrying its own copy of
    // the playlist list: the sheet outlives any one track, so a menu rendered
    // with an item baked in would be wrong the moment the track changed.
    document.addEventListener('soundchex:add-to-playlist', async (event) => {
        const items = event.detail?.items ?? [];

        if (items.length === 0) return;

        // Reuse a menu already on the page — any of them can act on any items,
        // since the ids travel with the request rather than the markup.
        const menu = document.querySelector('.track-menu');

        if (!menu) return;

        menu.dataset.items = JSON.stringify(items);
        menu.setAttribute('open', '');

        // Brought to the sheet, which is above everything else on screen.
        menu.style.position = 'fixed';
        menu.style.zIndex = '70';
        menu.style.insetInlineEnd = '1rem';
        menu.style.bottom = '6rem';
    });

    // A dropdown that stays open after the pointer has gone elsewhere reads as
    // stuck, so clicking outside closes it.
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.add-to-playlist[open], .track-menu[open]').forEach((holder) => {
            if (!holder.contains(event.target)) holder.removeAttribute('open');
        });
    });
}

document.addEventListener('livewire:navigated', () => setupPlaylists());

setupPlaylists();

export { setupPlaylists };
