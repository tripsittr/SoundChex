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

    dbPromise = new Promise((resolve, reject) => {
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

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    return dbPromise;
}

async function transaction(store, mode, fn) {
    const db = await openDatabase();

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
 * Whether a file of this size plausibly fits.
 *
 * Deliberately advisory. The browser's own figure is an estimate, and a 1.9 GB
 * film is fine on a desktop and impossible on iOS — so this reports and the
 * user decides, rather than refusing on a guess.
 */
export async function checkSpace(bytes) {
    const estimate = await storageEstimate();

    if (!estimate || !estimate.quota) {
        return { known: false, fits: true, free: 0, needed: bytes };
    }

    // A margin, because writing right up to the quota tends to fail partway.
    const headroom = estimate.free - bytes;

    return {
        known: true,
        fits: headroom > 50 * 1024 * 1024,
        free: estimate.free,
        needed: bytes,
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
export async function download({ id, url, meta = {}, onProgress, signal }) {
    await requestPersistence();

    const response = await fetch(url, { signal });

    if (!response.ok) {
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

    // Stored as an ArrayBuffer with its type beside it, not as a Blob.
    //
    // Safari aborts the transaction when a Blob is put into IndexedDB, and
    // does it with a null error — so a download appeared to fail for no
    // reason, and the bytes that had just been written were rolled back. This
    // is why downloads worked in Chrome and never on the phone.
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
