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
 * The icon download buttons, in song rows and in the now-playing sheet.
 *
 * These render a [data-download] button carrying the item on the element
 * itself, and nothing bound them — every one of them was inert, so tapping
 * download in a song list or on the full-screen player did nothing at all and
 * reported nothing either.
 *
 * Delegated on document rather than bound per button: rows arrive with SPA
 * navigation and the sheet re-points its button at each track, so anything
 * bound to a specific element goes stale. One listener covers every button
 * that exists now or later.
 */
export function setupIconDownloads() {
    if (!window.indexedDB || window.__soundchexIconDownloads) return;

    window.__soundchexIconDownloads = true;

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-download]');

        // The detail-page control is bound separately and owns its own state;
        // it carries a label element these icon buttons do not have.
        if (!button || button.id === 'download-toggle') return;

        event.preventDefault();

        const id = button.dataset.download;
        const url = button.dataset.downloadUrl;

        if (!id || !url) return;

        // Guards a second tap while the first is still running: the button
        // stays in the DOM and is still clickable mid-transfer.
        if (button.dataset.state === 'downloading') return;

        const title = button.dataset.downloadTitle ?? '';
        const type = button.dataset.downloadType ?? '';

        if (await isDownloaded(id)) {
            // Confirmed, because this deletes the audio from the device and the
            // same button both downloads and removes — a mis-tap on a stored
            // track silently threw away a file the user may have downloaded
            // precisely because they were about to lose their connection.
            const confirmed = window.confirm(
                `Remove ${title || 'this track'} from downloads?\n\n`
                + 'It will need to be downloaded again to play offline.',
            );

            if (!confirmed) return;

            await remove(id);
            button.dataset.state = 'idle';
            button.setAttribute('aria-label', `Download ${title}`);

            return;
        }

        button.dataset.state = 'downloading';
        button.setAttribute('aria-label', `Downloading ${title}`);

        try {
            await download({ id, url, meta: { title, type, url } });

            button.dataset.state = 'stored';
            button.setAttribute('aria-label', `${title} downloaded — tap to remove`);
        } catch (error) {
            // Reported on the button itself: these live in a scrolling list
            // with no status line to write to, so a silent failure is
            // indistinguishable from a download that simply has not finished.
            button.dataset.state = 'failed';
            button.setAttribute('aria-label', `Download failed for ${title} — tap to retry`);

            if (error?.name !== 'AbortError') {
                console.error('Download failed', error);
            }
        }
    });
}

/**
 * Marks buttons whose item is already on the device.
 *
 * Without this a stored track shows an idle download icon until it is tapped,
 * which invites downloading the same file twice.
 */
// The offline shell rebuilds rows from the mirror and cannot import this
// module, so it asks by event instead.
if (!window.__soundchexRepaintBound) {
    window.__soundchexRepaintBound = true;

    document.addEventListener('soundchex:repaint-downloads', () => {
        paintIconDownloadStates();
    });
}

export async function paintIconDownloadStates() {
    const buttons = document.querySelectorAll('[data-download]:not(#download-toggle)');

    if (buttons.length === 0) return;

    const stored = new Set((await list()).map((entry) => String(entry.id)));

    buttons.forEach((button) => {
        const id = button.dataset.download;

        if (!id) return;

        button.dataset.state = stored.has(id) ? 'stored' : 'idle';
    });
}

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
