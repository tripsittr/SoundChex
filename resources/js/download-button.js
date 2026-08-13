import {
    checkSpace,
    download,
    formatBytes,
    isDownloaded,
    list,
    localUrl,
    remove,
} from './downloads.js';

// Exposed for the browser tests, which drive the real download path — fetch,
// stream, IndexedDB — rather than clicking through four button states. There
// is no other way in: the bundle has no importable module path at runtime.
// Read-only surface, and the same object the UI uses, so a test cannot pass
// against a parallel implementation.
window.soundchexDownloads = { checkSpace, download, isDownloaded, list, localUrl, remove };

/**
 * The download control on a detail page.
 *
 * Four states, and the button says which it is in rather than relying on an
 * icon: available, downloading with progress, stored with its size, or
 * unavailable.
 */
export function setupDownloadButton() {
    const button = document.getElementById('download-toggle');

    if (!button || !window.indexedDB) return;

    // Marked on the element rather than tracked in a module variable: this
    // runs on first load and again after every SPA navigation, and the second
    // call would otherwise add a second click handler to the same button —
    // one tap, two downloads.
    if (button.dataset.bound === 'true') return;

    button.dataset.bound = 'true';

    const id = button.dataset.itemId;
    const url = button.dataset.url;
    const title = button.dataset.title ?? '';
    const type = button.dataset.type ?? '';
    const sizeHint = Number(button.dataset.size ?? 0);

    const label = button.querySelector('[data-download-label]');
    const status = document.getElementById('download-status');

    let controller = null;

    const setLabel = (text) => {
        if (label) label.textContent = text;
    };

    const say = (message, tone = 'muted') => {
        if (!status) return;

        status.textContent = message ?? '';
        status.dataset.tone = tone;
        status.classList.toggle('hidden', !message);
    };

    const render = async () => {
        if (await isDownloaded(id)) {
            button.dataset.state = 'stored';
            setLabel('Downloaded — tap to remove');

            return;
        }

        button.dataset.state = 'idle';
        setLabel('Download for offline');
    };

    const start = async () => {
        // The browser's figure is an estimate and platforms differ wildly, so
        // this reports and the user decides rather than refusing outright.
        if (sizeHint > 0) {
            const space = await checkSpace(sizeHint);

            if (space.known && !space.fits) {
                const proceed = window.confirm(
                    `${title} is ${formatBytes(sizeHint)}, and this device has about `
                    + `${formatBytes(space.free)} free.\n\n`
                    + 'The download will probably fail partway. Try anyway?',
                );

                if (!proceed) return;
            }
        }

        controller = new AbortController();
        button.dataset.state = 'downloading';

        try {
            await download({
                id,
                url,
                meta: { title, type, url },
                signal: controller.signal,
                onProgress: (loaded, total) => {
                    setLabel(total
                        ? `Downloading ${Math.round((loaded / total) * 100)}% — tap to cancel`
                        : `Downloading ${formatBytes(loaded)} — tap to cancel`);
                },
            });

            say('Saved to this device. It will play without a connection.', 'good');
            await render();
        } catch (error) {
            if (error.name === 'AbortError') {
                // Nothing is written until the transfer completes, so a
                // cancelled download leaves no partial file behind.
                say('Download cancelled.');
            } else {
                say('Download failed. Check the connection and try again.', 'bad');
            }

            await render();
        } finally {
            controller = null;
        }
    };

    button.addEventListener('click', async () => {
        if (controller) {
            controller.abort();

            return;
        }

        if (button.dataset.state === 'stored') {
            await remove(id);
            say('Removed from this device.');
            await render();

            return;
        }

        await start();
    });

    render();
}

/**
 * Points a media element at a stored copy when one exists.
 *
 * The same player either way — an offline film shouldn't need a separate mode.
 * Returns a cleanup function that revokes the blob URL, which must be called:
 * a live URL pins the whole file in memory.
 */
export async function useLocalSource(element, id) {
    if (!element || !id || !window.indexedDB) return () => {};

    const url = await localUrl(id);

    if (!url) return () => {};

    const wasPlaying = !element.paused;
    const position = element.currentTime;

    element.src = url;

    // Restoring position matters here: switching source resets it, and this
    // may run after playback has already started from the network.
    element.addEventListener('loadedmetadata', () => {
        if (position > 0) element.currentTime = position;
        if (wasPlaying) element.play().catch(() => {});
    }, { once: true });

    return () => URL.revokeObjectURL(url);
}

/**
 * Downloads a whole album in one action.
 *
 * An album is a dozen taps otherwise. Tracks are fetched one at a time rather
 * than in parallel: a phone on cellular handles a single stream far better,
 * and sequential progress is the honest thing to show.
 */
export function setupBatchDownload() {
    const button = document.getElementById('download-album');

    if (!button || !window.indexedDB) return;

    if (button.dataset.bound === 'true') return;

    button.dataset.bound = 'true';

    let tracks;

    try {
        tracks = JSON.parse(button.dataset.tracks ?? '[]');
    } catch {
        return;
    }

    if (tracks.length === 0) return;

    const label = button.querySelector('[data-download-label]');
    const status = document.getElementById('download-status');

    let controller = null;

    button.addEventListener('click', async () => {
        if (controller) {
            controller.abort();

            return;
        }

        const totalBytes = tracks.reduce((sum, t) => sum + (t.size ?? 0), 0);
        const space = await checkSpace(totalBytes);

        if (space.known && !space.fits) {
            const proceed = window.confirm(
                `This album is ${formatBytes(totalBytes)}, and this device has about `
                + `${formatBytes(space.free)} free.\n\nTry anyway?`,
            );

            if (!proceed) return;
        }

        controller = new AbortController();
        button.dataset.state = 'downloading';

        let done = 0;
        let skipped = 0;

        for (const track of tracks) {
            if (controller.signal.aborted) break;

            // Already-downloaded tracks are counted rather than re-fetched, so
            // resuming an interrupted album doesn't start from scratch.
            // eslint-disable-next-line no-await-in-loop
            if (await isDownloaded(track.id)) {
                skipped++;
                done++;
                continue;
            }

            if (label) {
                label.textContent = `Downloading ${done + 1} of ${tracks.length} — tap to cancel`;
            }

            try {
                // eslint-disable-next-line no-await-in-loop
                await download({
                    id: track.id,
                    url: track.url,
                    meta: { title: track.title, type: 'music', url: track.url },
                    signal: controller.signal,
                });

                done++;
            } catch (error) {
                if (error.name === 'AbortError') break;

                // One bad track shouldn't abandon the album.
                done++;
            }
        }

        const cancelled = controller.signal.aborted;
        controller = null;
        button.dataset.state = 'idle';

        if (label) label.textContent = 'Download album';

        if (status) {
            status.textContent = cancelled
                ? `Stopped after ${done} of ${tracks.length}.`
                : `Album saved to this device${skipped > 0 ? ` (${skipped} already had)` : ''}.`;
            status.dataset.tone = cancelled ? 'muted' : 'good';
            status.classList.remove('hidden');
        }
    });
}
