import * as queue from './download-queue.js';
import { log, logFailure, loggedFetch } from './log.js';

/**
 * How long to wait for the list of everything downloadable.
 *
 * Generous: it is thousands of rows, and the server spends half a second
 * sizing the files before the first byte is sent. Long enough for a slow
 * route, short enough to say so rather than hang.
 */
const LIBRARY_LIST_TIMEOUT = 30000;
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

        // Queued rather than started: five taps used to open five concurrent
        // transfers to the same server, which on a phone is slower than doing
        // them in turn and makes a dropped connection take down all five.
        const { queued, position, promise } = queue.enqueue(
            { id, url, title, type },
            runDownload,
        );

        if (!queued) return;

        button.dataset.state = 'downloading';
        button.setAttribute('aria-label', position > 1
            ? `${title} is number ${position} in the download queue`
            : `Downloading ${title}`);

        // Only worth saying when something is actually waiting behind another
        // download — a toast for every single tap is noise.
        if (position > 1) {
            toast(`Added to the download queue — ${position - 1} ahead`);
        }

        try {
            await promise;

            // Re-found rather than reused. A library refresh replaces every row
            // while a download is in flight — `main.replaceChildren()` — so the
            // element captured at click time is detached by the time this
            // resolves, and writing `stored` onto it updates nothing anyone can
            // see. The file is on the device and the icon says it is not.
            const live = document.querySelector(
                `[data-download="${CSS.escape(id)}"]:not(#download-toggle)`,
            ) ?? button;

            live.dataset.state = 'stored';
            live.setAttribute('aria-label', `${title} downloaded — tap to remove`);

            // Only worth a notification when it was queued behind something
            // else: a download that finishes while you are watching the button
            // does not need the OS to tell you about it.
            if (position > 1) {
                const { notify } = await import('./device-settings.js');

                notify('Download finished', `${title} is ready to play offline.`);
            }
        } catch (error) {
            // Reported on the button itself: these live in a scrolling list
            // with no status line to write to, so a silent failure is
            // indistinguishable from a download that simply has not finished.
            // Re-found for the same reason as the success path above.
            const live = document.querySelector(
                `[data-download="${CSS.escape(id)}"]:not(#download-toggle)`,
            ) ?? button;

            live.dataset.state = 'failed';
            live.setAttribute('aria-label', `Download failed for ${title} — tap to retry`);

            if (error?.name !== 'AbortError') {
                console.error('Download failed', error);

                // Worth telling someone about: the red icon is only visible if
                // they happen to be looking at that row, and a download queued
                // for a flight that failed silently is the case notifications
                // exist for.
                const { notify } = await import('./device-settings.js');

                notify('Download failed', `${title} could not be downloaded.`);
            }
        }
    });
}

/**
 * Download several tracks at once.
 *
 * Album, playlist and the track menu all render a [data-download-batch] button
 * carrying its tracks on the element — and nothing was bound to any of them.
 * setupBatchDownload() binds a single element by id, so every one of these was
 * inert: tapping "Download this album" did nothing and said nothing.
 *
 * Delegated on document, because these arrive with SPA navigation and the
 * offline shell rebuilds them from the mirror.
 */
/**
 * The runner every queued download uses.
 *
 * Defined once so a queue picked up from storage — where the function could not
 * be kept — is resumed with exactly what it was started with.
 */
function runDownload(item) {
    return download({
        id: item.id,
        url: item.url,
        meta: { title: item.title, type: item.type ?? 'music', url: item.url },
    });
}

/**
 * Picks up a queue interrupted by going offline or closing the app.
 *
 * Both are the same problem from the queue's point of view: work was left
 * unfinished and the connection is back.
 */
export function resumeDownloads() {
    if (!window.indexedDB || window.__soundchexResumeBound) return;

    window.__soundchexResumeBound = true;

    const pickUp = () => {
        if (navigator.onLine === false) return;

        const { resumed } = queue.resume(runDownload);

        if (resumed > 0) {
            toast(`Resuming ${resumed} download${resumed === 1 ? '' : 's'}.`);
        }
    };

    window.addEventListener('online', pickUp);

    // On returning to the app, which on a phone is when a connection most often
    // comes back without an online event firing.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') pickUp();
    });

    pickUp();
}

export function setupBatchDownloads() {
    if (!window.indexedDB || window.__soundchexBatchBound) return;

    window.__soundchexBatchBound = true;

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-download-batch]');

        if (!button) return;

        event.preventDefault();

        if (button.dataset.state === 'downloading') return;

        let tracks;

        try {
            tracks = JSON.parse(button.dataset.tracks ?? '[]');
        } catch {
            return;
        }

        if (!Array.isArray(tracks) || tracks.length === 0) return;

        await runBatch(button, tracks, button.dataset.batchLabel ?? 'these tracks');
    });

    // The whole library, which is not on the page: the songs list is paginated,
    // so the tracks have to be asked for rather than read from the DOM.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-download-library]');

        if (!button) return;

        event.preventDefault();

        if (button.dataset.state === 'downloading') return;

        const label = button.querySelector('[data-download-label]');

        if (label) label.textContent = 'Checking…';

        const started = performance.now();

        log('download:library-requested', {});

        try {
            // The list is every downloadable track, which on this library is
            // thousands of rows and megabytes of JSON. It is also the slowest
            // thing this button does, so how long it took is worth keeping:
            // "Checking…" sitting there is the symptom people report.
            // Bounded, because it was not. The list is ~0.7 MB of JSON for
            // 5,500 tracks and takes half a second to build before it is even
            // sent, so over a slow route "Checking…" sat there indefinitely
            // with no way to tell a slow response from a dead one.
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), LIBRARY_LIST_TIMEOUT);

            const response = await loggedFetch('download:library', '/app/downloadable', {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            }).finally(() => clearTimeout(timer));

            if (!response.ok) {
                throw new Error(`Library list failed (${response.status})`);
            }

            const { tracks } = await response.json();

            log('download:library-listed', {
                tracks: tracks?.length ?? 0,
                ms: Math.round(performance.now() - started),
            });

            if (!tracks?.length) {
                if (label) label.textContent = 'Download all';
                toast('Nothing to download.');

                return;
            }

            await runBatch(button, tracks, 'Your library');
        } catch (error) {
            // Previously a bare toast with nothing recorded on either side, so
            // "it says Checking and then fails" had no cause to look up.
            logFailure('download:library:failed', error, {
                ms: Math.round(performance.now() - started),
            });

            button.dataset.state = 'failed';

            if (label) label.textContent = 'Download all';

            toast(({
                AbortError: 'Timed out reading the library. Try again on a faster connection.',
                TypeError: 'Could not reach the library.',
            })[error?.name] ?? 'The library list could not be read.');
        }
    });
}

/**
 * Queues a set of tracks, reporting progress on the button.
 *
 * Everything goes through the same serial queue as a single download, so a
 * hundred tracks do not open a hundred connections — and a track already on the
 * device is skipped rather than fetched again, which is what makes this safe to
 * press twice.
 */
/**
 * A batch label as the subject of a sentence.
 *
 * The label reaches three messages as a subject — "… is already on this
 * device", "… is 4.2 GB", "… is on this device" — and the fallback for a set
 * of tracks is the plural "these tracks", which made all three read "these
 * tracks is". It also began a sentence in lower case.
 *
 * Returns the label with its first letter raised and the verb that agrees with
 * it, rather than rewording each message: the label is the only part that
 * varies, so the agreement belongs with it.
 */
function subject(label) {
    const text = String(label ?? '').trim() || 'these tracks';

    // Plural only when we chose the wording, not by guessing at an album or
    // artist name — "The Beatles is" is wrong but "Abbey Road are" is worse,
    // and a title ending in s is no guide to either.
    const plural = text === 'these tracks';

    return {
        text: text.charAt(0).toUpperCase() + text.slice(1),
        // Lower case for the middle of a sentence.
        inline: text,
        verb: plural ? 'are' : 'is',
    };
}

/**
 * Asks about space before a download, and reports what was decided.
 *
 * One place rather than three, because the three call sites had drifted into
 * three different sentences for the same situation and none of them handled a
 * missing figure at all: `checkSpace()` used to answer an unknown quota with
 * `fits: true`, so a platform reporting nothing downloaded silently.
 *
 * Returns true to proceed. Every outcome is logged with its numbers — a
 * refusal nobody can explain afterwards is the failure this exists to stop.
 *
 * @returns {Promise<boolean>}
 */
async function confirmSpace(bytes, description) {
    if (!(bytes > 0)) return true;

    const space = await checkSpace(bytes);

    log('download:space', {
        needed: bytes,
        known: space.known,
        fits: space.fits,
        tight: space.tight ?? false,
        free: space.free,
    });

    // No figure at all. Ask rather than assume: this used to read as room.
    if (!space.known) {
        return window.confirm(
            `${description} is ${formatBytes(bytes)}.\n\n`
            + 'This device does not report how much space is free, so this '
            + 'may not fit.\n\nDownload anyway?',
        );
    }

    if (!space.fits) {
        return window.confirm(
            `${description} is ${formatBytes(bytes)}, and this device has `
            + `${formatBytes(space.free)} free.\n\n`
            + 'There will not be enough room, and the download will probably '
            + 'fail partway.\n\nTry anyway?',
        );
    }

    if (space.tight) {
        return window.confirm(
            `${description} is ${formatBytes(bytes)}, which would leave about `
            + `${formatBytes(space.after)} free on this device.\n\nContinue?`,
        );
    }

    return true;
}

async function runBatch(button, tracks, label) {
    const setBatchLabel = (text) => {
        const el = button.querySelector('[data-download-label]');

        if (el) el.textContent = text;

        button.setAttribute('aria-label', text);
    };

    const stored = new Set((await list()).map((entry) => String(entry.id)));
    const missing = tracks.filter((track) => !stored.has(String(track.id)));

    if (missing.length === 0) {
        const { text, verb } = subject(label);

        toast(`${text} ${verb} already on this device.`);

        button.dataset.state = 'stored';
        setBatchLabel('Downloaded');

        return;
    }

    // Asked before starting, not part way through. The estimate is what the
    // server reported for each file, so it is a real total rather than a guess.
    const bytes = missing.reduce((sum, track) => sum + (track.size ?? 0), 0);

    if (! await confirmSpace(bytes, subject(label).text)) return;

    button.dataset.state = 'downloading';
    toast(`Queued ${missing.length} track${missing.length === 1 ? '' : 's'}.`);

    let done = 0;
    let failed = 0;

    // Every row this batch will touch shows a spinner straight away.
    //
    // Only the batch button was marked before, so downloading a whole album or
    // library left every song row sitting on the idle arrow until its file
    // landed — the rows say nothing is happening while the thing that is
    // happening is downloading them. On a slow route that is minutes of a list
    // that looks untouched.
    //
    // Set from the ids rather than by walking the DOM, because the rows on
    // screen are one page of a paginated list and the batch is the whole
    // library: a row that is not rendered yet gets its state when it arrives,
    // from `paintIconDownloadStates()`.
    const markRow = (id, state) => {
        const row = document.querySelector(
            `[data-download="${CSS.escape(String(id))}"]:not(#download-toggle)`,
        );

        if (row) row.dataset.state = state;
    };

    // Tracks whose download failed, so the repaint below does not quietly
    // return them to idle: IndexedDB has nothing to say about a file that was
    // never written, and "failed" is information the user needs.
    const failures = new Set();

    missing.forEach((track) => markRow(track.id, 'downloading'));

    const results = missing.map((track) => {
        const { promise } = queue.enqueue(
            { id: track.id, url: track.url, title: track.title, type: 'music' },
            (item) => download({
                id: item.id,
                url: item.url,
                meta: { title: item.title, type: 'music', url: item.url },
            }),
        );

        return promise
            .then(() => {
                done += 1;

                // Marked here, from the result, rather than left to the
                // repaint below. `paintIconDownloadStates()` deliberately
                // skips any button already reading `downloading` — that guard
                // is what stops it resetting a transfer in flight to idle —
                // and every row in this batch was set to `downloading` before
                // it started. So the repaint skipped precisely the rows whose
                // outcome it was meant to write, and a finished track sat on
                // its spinner until the page was navigated away from and back.
                markRow(track.id, 'stored');
            })
            .catch(() => {
                failed += 1;
                failures.add(String(track.id));
                markRow(track.id, 'failed');
            })
            .finally(async () => {
                setBatchLabel(`Downloading ${done + failed} of ${missing.length}`);

                // Repaint first, then re-mark what it cannot know about. It
                // reads IndexedDB, so a track that *failed* is simply absent
                // there and comes back as `idle` — indistinguishable from one
                // never asked for. Marking before the repaint loses the state
                // it just set; marking after keeps it.
                await paintIconDownloadStates();

                failures.forEach((id) => markRow(id, 'failed'));
            });
    });

    await Promise.allSettled(results);

    // Repaint before the final states, not after. It reads IndexedDB, where a
    // failed track simply is not — so it returns every one of them to `idle`,
    // and a batch in which nothing downloaded ended up looking untouched. The
    // button said `failed` for as long as it took the repaint to resolve.
    await paintIconDownloadStates();

    // Re-found, not reused. A library refresh mid-batch replaces `main` — and
    // with it this button — so the element captured when the batch started is
    // detached by now and writing to it reports nothing. The batch button came
    // back from the server as `idle`, so a run in which every download failed
    // read as one that never happened.
    //
    // Found by whichever attribute this button carries rather than a fixed
    // selector: `runBatch()` serves the album and playlist buttons as well,
    // and a hardcoded `[data-download-library]` wrote the outcome of an album
    // download onto a button on a different page — or, once that button was
    // removed, onto nothing at all.
    const marker = ['data-download-library', 'data-download-batch']
        .find((name) => button.hasAttribute(name));

    const liveButton = (marker && document.querySelector(`[${marker}]`)) ?? button;

    liveButton.dataset.state = failed === 0 ? 'stored' : 'failed';

    const liveLabel = liveButton.querySelector('[data-download-label]');
    const finalText = failed === 0 ? 'Downloaded' : `${failed} failed`;

    if (liveLabel) liveLabel.textContent = finalText;

    liveButton.setAttribute('aria-label', finalText);

    // Re-marked after the repaint for the same reason.
    failures.forEach((id) => markRow(id, 'failed'));

    toast(failed === 0
        ? `${subject(label).text} ${subject(label).verb} on this device.`
        : `${done} downloaded, ${failed} failed.`);
}

/**
 * Marks buttons whose item is already on the device.
 *
 * Without this a stored track shows an idle download icon until it is tapped,
 * which invites downloading the same file twice.
 */
/**
 * A brief message at the foot of the screen.
 *
 * Built here rather than rendered into every page: these rows appear in lists
 * the offline shell also builds, and a toast anchored to page markup would be
 * missing from half of them.
 */
function toast(message) {
    let host = document.getElementById('soundchex-toast');

    if (!host) {
        host = document.createElement('div');
        host.id = 'soundchex-toast';
        host.className = 'toast';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.append(host);
    }

    host.textContent = message;
    host.dataset.visible = 'true';

    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { host.dataset.visible = 'false'; }, 2600);
}

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

    // Cleared first: an SPA swap brings buttons this has not read yet, and a
    // flag left true from the previous page would say they were painted.
    window.__soundchexDownloadsPainted = false;

    const stored = new Set((await list()).map((entry) => String(entry.id)));

    buttons.forEach((button) => {
        const id = button.dataset.download;

        if (!id) return;

        // A transfer in flight owns its own button. This reads IndexedDB and
        // then writes every state, so without this it lands mid-download and
        // resets the spinner to idle — the download keeps running and the
        // button says it never started, which is indistinguishable from a tap
        // that did nothing. It also erases "number 2 in the queue".
        if (button.dataset.state === 'downloading') return;

        button.dataset.state = stored.has(id) ? 'stored' : 'idle';
    });

    // Announced because this lands *after* an await, so anything that set a
    // state before it resolved is silently overwritten. It runs twice on a
    // cold load — once from `bindPageScripts()` and again on
    // `livewire:navigated` — and there was no way to tell it had finished,
    // which made "the button shows the wrong glyph" a race nobody could see.
    //
    // A flag as well as an event: the event is gone by the time anything that
    // arrives later could listen for it, and "has this run yet?" is the
    // question being asked.
    window.__soundchexDownloadsPainted = true;

    document.dispatchEvent(new CustomEvent('soundchex:downloads-painted', {
        detail: { buttons: buttons.length, stored: stored.size },
    }));
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
        // Reports and lets the user decide rather than refusing outright —
        // but an unknown figure now asks too, where it used to say yes.
        if (! await confirmSpace(sizeHint, title)) return;

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

        if (! await confirmSpace(totalBytes, 'This album')) return;

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
