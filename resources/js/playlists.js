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

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-add-to-playlist]');

        if (!button) return;

        event.preventDefault();

        const holder = button.closest('.add-to-playlist');
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
        } catch {
            button.textContent = 'Failed';
            setTimeout(() => {
                button.textContent = original;
                button.disabled = false;
            }, 1500);
        }
    });

    // A dropdown that stays open after the pointer has gone elsewhere reads as
    // stuck, so clicking outside closes it.
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.add-to-playlist[open]').forEach((holder) => {
            if (!holder.contains(event.target)) holder.removeAttribute('open');
        });
    });
}

document.addEventListener('livewire:navigated', () => setupPlaylists());

setupPlaylists();

export { setupPlaylists };
