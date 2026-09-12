import { log as logEvent } from './log.js';

/**
 * Offline downloads.
 *
 * Files are stored in IndexedDB as blobs rather than in the Cache API. The
 * Cache API cannot serve range requests, so a cached film plays from the start
 * and refuses to seek; a blob URL seeks natively.
 *
 * Storage is capped and evictable — Chrome allows a large share of free disk,
 * iOS Safari caps a PWA at roughly 1 GB and may reclaim it without warning. So
 * every read handles "it isn't there any more", and the first download asks
 * for persistent storage, which installed apps are granted far more often than
 * plain tabs.
 */

const DB_NAME = 'soundchex-downloads';
const DB_VERSION = 1;

/** Blobs and metadata are separate stores: listing downloads must not load
 *  gigabytes of file data to render a list of names. */
const BLOB_STORE = 'blobs';
const META_STORE = 'meta';

let dbPromise = null;

function openDatabase() {
    if (dbPromise) return dbPromise;

    // Captured so the handlers below only clear the cache if it is still
    // *their* connection cached — a later open must not be dropped by an
    // earlier connection's close event arriving late.
    let pending;

    pending = dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(BLOB_STORE)) {
                db.createObjectStore(BLOB_STORE);
            }

            if (!db.objectStoreNames.contains(META_STORE)) {
                db.createObjectStore(META_STORE, { keyPath: 'id' });
            }
        };

        request.onsuccess = () => {
            const db = request.result;

            // iOS closes an IndexedDB connection out from under the page —
            // backgrounding, memory pressure, storage eviction — and the handle
            // stays cached here. Every transaction afterwards throws "The
            // database connection is closing", forever, until the app restarts.
            //
            // Reported from the phone: one UnknownError from the IndexedDB
            // server, then 27 failed transactions piled up behind a connection
            // nobody had noticed was dead.
            //
            // Dropping the cached promise means the next call opens a fresh
            // connection rather than reusing a corpse.
            db.onclose = () => {
                if (dbPromise === pending) dbPromise = null;
            };

            // Another tab upgrading the schema. Close rather than block it, or
            // that tab hangs waiting for this one.
            db.onversionchange = () => {
                db.close();

                if (dbPromise === pending) dbPromise = null;
            };

            resolve(db);
        };

        request.onerror = () => {
            if (dbPromise === pending) dbPromise = null;

            reject(request.error);
        };
    });

    return dbPromise;
}

async function transaction(store, mode, fn, retrying = false) {
    const db = await openDatabase();

    try {
        return await runTransaction(db, store, mode, fn);
    } catch (error) {
        // A connection that died between opening and using it. Drop it and try
        // once more with a fresh one — the alternative is failing a download
        // the user asked for because the page was backgrounded a moment ago.
        if (! retrying && isClosedConnection(error)) {
            dbPromise = null;

            return transaction(store, mode, fn, true);
        }

        throw error;
    }
}

/**
 * Whether this error means the connection is gone rather than the data is bad.
 */
function isClosedConnection(error) {
    const name = error?.name ?? '';
    const message = String(error?.message ?? '');

    return name === 'InvalidStateError'
        || name === 'UnknownError'
        || message.includes('connection is closing')
        || message.includes('database connection is closing');
}

function runTransaction(db, store, mode, fn) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const result = fn(tx.objectStore(store));

        // `result` is an IDBRequest for get/getAll, and undefined for delete.
        // Unwrapping with `result?.result ?? result` looked equivalent but
        // returned the *request object* whenever the lookup found nothing —
        // truthy, so callers treated a missing record as a hit and
        // URL.createObjectURL() threw on it.
        tx.oncomplete = () => resolve(
            result instanceof IDBRequest ? result.result : result,
        );
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/* ------------------------------------------------------------- storage --- */

/**
 * Asks the browser not to evict our data.
 *
 * Granted far more readily to an installed app than to a tab, which is the
 * practical reason to recommend add-to-home-screen.
 */
export async function requestPersistence() {
    if (!navigator.storage?.persist) return false;

    try {
        return (await navigator.storage.persisted()) || (await navigator.storage.persist());
    } catch {
        return false;
    }
}

/**
 * @returns {Promise<{usage: number, quota: number, free: number}|null>}
 */
export async function storageEstimate() {
    if (!navigator.storage?.estimate) return null;

    try {
        const { usage = 0, quota = 0 } = await navigator.storage.estimate();

        return { usage, quota, free: Math.max(0, quota - usage) };
    } catch {
        return null;
    }
}

/**
 * Enough left afterwards that the write itself will not fail.
 *
 * Sized against the **quota**, not the disk, because that is what this can
 * see. A browser quota is a slice the engine has set aside — a 1 GB cap on a
 * 128 GB phone is normal — so a reserve chosen for "the device still works"
 * would refuse every download on a device with plenty of room. That check
 * belongs on a real `statvfs` reading and arrives with native storage
 * (S-107 step 2); until then this only claims what it can measure.
 *
 * Deliberately small, and the reason is measurable: the e2e browser reports a
 * 1,048,576,000-byte quota with 0.98 GB free. A 2 GB reserve refused
 * everything there, which is what a device-scale margin does to a quota-scale
 * number.
 */
const RESERVE_BYTES = 64 * 1024 * 1024;

/**
 * Below this share of the quota left over, say so.
 *
 * A tenth of a 1 GB quota is ~100 MB — small enough to be worth a sentence
 * before someone fills it, and not so eager that a routine album download
 * asks a question nobody needs.
 */
const TIGHT_FRACTION = 0.1;

/**
 * Whether a download of this size fits, and what it leaves behind.
 *
 * Three outcomes, not two, because "no" and "I cannot tell" are different
 * answers and were previously the same one:
 *
 *   - `known: false` — no figure available. **`fits` is now `false`**, so an
 *     unknown reads as "ask" rather than "yes". It used to return `true`,
 *     which meant a platform that reports nothing downloaded 40 GB without a
 *     word — the failure this is supposed to prevent.
 *   - `fits: false` — it does not fit, or would leave the device unusable.
 *   - `tight: true` — it fits, and leaves little. The caller warns rather than
 *     refuses: a device left nearly full is the user's call to make, but it
 *     has to be a call rather than a discovery.
 *
 * The figure itself is the browser's quota, which is **not** free disk space:
 * on iOS it is bounded by the ~1 GB IndexedDB cap, so it understates a 128 GB
 * phone by two orders of magnitude. `space()` on the storage backend replaces
 * it with a real `statvfs` reading once downloads are native (S-107 step 2);
 * until then this is the only number available and is treated as advisory.
 */
export async function checkSpace(bytes) {
    const estimate = await storageEstimate();

    if (!estimate || !estimate.quota) {
        // Fails closed. See above: an unknown is a question, not permission.
        return { known: false, fits: false, tight: false, free: 0, needed: bytes };
    }

    const after = estimate.free - bytes;

    return {
        known: true,
        fits: after > RESERVE_BYTES,
        // Fits, but leaves under a tenth of the quota — worth saying so.
        tight: after > RESERVE_BYTES && after < estimate.quota * TIGHT_FRACTION,
        free: estimate.free,
        needed: bytes,
        after: Math.max(0, after),
    };
}

/* ------------------------------------------------------------ downloads --- */

/**
 * Stores a file for offline use.
 *
 * The blob is written only once the transfer completes: a partial file that
 * looked downloaded would play as a truncated track or a corrupt book.
 *
 * @param {object} options
 * @param {number|string} options.id      Media item id
 * @param {string} options.url            Where to fetch it
 * @param {object} options.meta           Title, type, and anything the list shows
 * @param {(loaded: number, total: number) => void} [options.onProgress]
 * @param {AbortSignal} [options.signal]
 */

export async function download(options) {
    try {
        return await runDownload(options);
    } catch (error) {
        // Every way a download can fail, in one place. Without this the only
        // trace was an unhandled rejection with no id attached to it, which is
        // exactly what the phone was reporting.
        logEvent('download:failed', {
            id: String(options?.id ?? ''),
            name: error?.name ?? '',
            reason: String(error?.message ?? error).slice(0, 160),
            aborted: error?.name === 'AbortError',
        });

        throw error;
    }
}

async function runDownload({ id, url, meta = {}, onProgress, signal, force = false }) {
    await requestPersistence();

    const startedAt = Date.now();

    logEvent('download:start', { id: String(id), force });

    // Already here, so there is nothing to fetch.
    //
    // The stores are keyed by id and written with put(), so a second download
    // overwrote the first rather than duplicating it — but it still transferred
    // the whole file again, which on a metered connection is the part that
    // costs. The callers that guard against this do so individually; doing it
    // here means none of them can forget.
    if (! force) {
        const existing = await transaction(META_STORE, 'readonly', (store) => store.get(String(id)));

        if (existing?.size > 0) {
            onProgress?.(existing.size, existing.size);

            logEvent('download:already-stored', { id: String(id), size: existing.size });

            return { id: String(id), size: existing.size, alreadyStored: true };
        }
    }

    const response = await fetch(url, { signal });

    if (!response.ok) {
        logEvent('download:http-error', { id: String(id), status: response.status });

        throw new Error(`Download failed (${response.status})`);
    }

    const total = Number(response.headers.get('content-length')) || 0;
    const type = response.headers.get('content-type') || 'application/octet-stream';

    // Streamed so progress can be reported. Without a body reader the only
    // options are no progress at all or loading it twice.
    const chunks = [];
    let loaded = 0;

    if (response.body?.getReader) {
        const reader = response.body.getReader();

        for (;;) {
            const { done, value } = await reader.read();

            if (done) break;

            chunks.push(value);
            loaded += value.length;

            onProgress?.(loaded, total);
        }
    } else {
        // Safari has shipped streams for years, but a fallback costs little
        // and the alternative is no download at all.
        const blob = await response.blob();
        chunks.push(new Uint8Array(await blob.arrayBuffer()));
        loaded = blob.size;
        onProgress?.(loaded, loaded);
    }

    const blob = new Blob(chunks, { type });

    const stored = await storeBlob(String(id), blob, { type, ...meta });

    logEvent('download:stored', {
        id: String(id),
        size: stored.size,
        ms: Date.now() - startedAt,
        // A mismatch here means the transfer was truncated and the file is
        // stored short — which plays as a track that stops early rather than
        // as an error anyone would notice.
        expected: total || null,
        truncated: total > 0 && stored.size !== total,
    });

    return stored;
}

/**
 * Writes one blob and its metadata to the store.
 *
 * The single write primitive, extracted so the storage interface
 * (`offline/storage.js`) has one thing to call and the ArrayBuffer workaround
 * lives in exactly one place.
 *
 * Stored as an ArrayBuffer with its type beside it, not as a Blob: Safari
 * aborts the transaction when a Blob is put into IndexedDB, and does it with a
 * null error — so a download appeared to fail for no reason and the bytes just
 * written were rolled back. This is why downloads worked in Chrome and never on
 * the phone.
 *
 * @param {string} id
 * @param {Blob} blob
 * @param {object} meta  type, and anything the download list shows
 * @returns {Promise<{ id: string, size: number }>}
 */
export async function storeBlob(id, blob, meta = {}) {
    const type = meta.type || blob.type || 'application/octet-stream';
    const buffer = await blob.arrayBuffer();

    await transaction(BLOB_STORE, 'readwrite', (store) => store.put(
        { buffer, type },
        String(id),
    ));

    // Written after the blob, so a metadata row always implies a real file.
    await transaction(META_STORE, 'readwrite', (store) => store.put({
        id: String(id),
        size: blob.size,
        type,
        downloadedAt: Date.now(),
        ...meta,
    }));

    return { id: String(id), size: blob.size };
}

/**
 * A blob URL for a stored file, or null when it isn't there.
 *
 * Callers must revoke the URL when finished — a live URL pins the whole file
 * in memory, which for a film is gigabytes.
 */
/**
 * Rebuilds a Blob from what was stored.
 *
 * Records are `{ buffer, type }` because Safari will not hold a Blob in
 * IndexedDB. An older record may still be a Blob itself, so both are handled —
 * anything already downloaded keeps working across the change.
 */
function toBlob(record) {
    if (!record) return null;
    if (record instanceof Blob) return record;

    return record.buffer
        ? new Blob([record.buffer], { type: record.type || 'application/octet-stream' })
        : null;
}

export async function localUrl(id) {
    const blob = toBlob(await transaction(BLOB_STORE, 'readonly', (store) => store.get(String(id))));

    // Type-checked rather than merely truthy, matching isDownloaded(): a
    // record that is not a Blob cannot become an object URL, and passing one
    // to createObjectURL throws rather than returning null.
    return blob instanceof Blob ? URL.createObjectURL(blob) : null;
}

/**
 * Whether a file is genuinely stored.
 *
 * Checks the blob, not the metadata: eviction can take the file and leave the
 * record, and a record alone would produce a player pointed at nothing.
 */
export async function isDownloaded(id) {
    const record = await transaction(BLOB_STORE, 'readonly', (store) => store.get(String(id)));

    return toBlob(record) !== null;
}

export async function remove(id) {
    await transaction(BLOB_STORE, 'readwrite', (store) => store.delete(String(id)));
    await transaction(META_STORE, 'readwrite', (store) => store.delete(String(id)));
}

/**
 * Everything stored, newest first, with evicted records pruned.
 */
export async function list() {
    const records = await transaction(META_STORE, 'readonly', (store) => store.getAll());
    const rows = Array.isArray(records) ? records : [];

    const present = [];

    for (const row of rows) {
        // eslint-disable-next-line no-await-in-loop
        if (await isDownloaded(row.id)) {
            present.push(row);
        } else {
            // The browser reclaimed the file. Drop the row rather than listing
            // something that cannot be played.
            // eslint-disable-next-line no-await-in-loop
            await remove(row.id);
        }
    }

    return present.sort((a, b) => (b.downloadedAt ?? 0) - (a.downloadedAt ?? 0));
}

export async function totalSize() {
    const rows = await list();

    return rows.reduce((sum, row) => sum + (row.size ?? 0), 0);
}

/** "1.4 GB" — sizes here are large enough that bytes are meaningless. */
export function formatBytes(bytes) {
    if (!bytes) return '0 MB';

    const mb = bytes / 1048576;

    return mb >= 1024
        ? `${(mb / 1024).toFixed(1)} GB`
        : `${Math.round(mb)} MB`;
}
