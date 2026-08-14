/**
 * The device's copy of the catalogue.
 *
 * A complete mirror rather than a cache of recent things: the whole library is
 * 0.61 MB of JSON — 94 KB gzipped — so holding all of it costs almost nothing
 * and is what makes browsing work with no network. A partial cache would leave
 * "which items do I have?" as a question the UI cannot answer offline.
 *
 * Its own database, not a new version of the downloads one. Bumping that would
 * run an upgrade transaction over a store holding gigabytes of media blobs,
 * and a failure there loses files the user chose to keep.
 */
const DB_NAME = 'soundchex-library';
const DB_VERSION = 1;
const ITEM_STORE = 'items';
const META_STORE = 'meta';

let dbPromise = null;

function openDatabase() {
    dbPromise ??= new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(ITEM_STORE)) {
                const store = db.createObjectStore(ITEM_STORE, { keyPath: 'id' });

                // Indexed because these are the two things every listing
                // filters on. Everything else is a scan over ~1,450 rows,
                // which is fast enough not to be worth an index to maintain.
                store.createIndex('type', 'type');
                store.createIndex('parent_id', 'parent_id');
            }

            if (!db.objectStoreNames.contains(META_STORE)) {
                db.createObjectStore(META_STORE);
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

        // Unwrapped by type: an IDBRequest whose lookup found nothing has a
        // `result` of undefined, and `result?.result ?? result` would fall
        // through to the request object itself — truthy, and the exact bug
        // that made a missing download look like a stored one.
        tx.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/* ------------------------------------------------------------- reading --- */

/** Every item the device holds. */
export async function all() {
    return (await transaction(ITEM_STORE, 'readonly', (store) => store.getAll())) ?? [];
}

export async function find(id) {
    return transaction(ITEM_STORE, 'readonly', (store) => store.get(Number(id)));
}

export async function count() {
    return (await transaction(ITEM_STORE, 'readonly', (store) => store.count())) ?? 0;
}

/** When the mirror was last brought up to date, as the server reported it. */
export async function syncedAt() {
    return transaction(META_STORE, 'readonly', (store) => store.get('synced_at'));
}

export async function storedEtag() {
    return transaction(META_STORE, 'readonly', (store) => store.get('etag'));
}

/* ------------------------------------------------------------- writing --- */

/**
 * Replaces the mirror wholesale.
 *
 * Used for a first sync and whenever the server's answer is the complete
 * library. Clearing first matters: merging a full payload into stale rows
 * would leave anything the server no longer sends — deleted, or newly blocked
 * by a rating cap — sitting on the device forever.
 */
export async function replaceAll(items, { syncedAt: stamp = null, etag = null } = {}) {
    await transaction(ITEM_STORE, 'readwrite', (store) => {
        store.clear();
        items.forEach((item) => store.put(item));
    });

    await writeMeta({ synced_at: stamp, etag });

    return items.length;
}

/**
 * Applies a delta.
 *
 * `removed` covers both deletion and a rating cap tightening, because the
 * device cannot tell those apart and does not need to: either way it must stop
 * holding the item.
 */
export async function applyDelta(items, removed = [], { syncedAt: stamp = null } = {}) {
    await transaction(ITEM_STORE, 'readwrite', (store) => {
        items.forEach((item) => store.put(item));
        removed.forEach((id) => store.delete(Number(id)));
    });

    await writeMeta({ synced_at: stamp });

    return { updated: items.length, removed: removed.length };
}

async function writeMeta({ synced_at = null, etag = null }) {
    await transaction(META_STORE, 'readwrite', (store) => {
        if (synced_at !== null) store.put(synced_at, 'synced_at');
        if (etag !== null) store.put(etag, 'etag');
    });
}

/**
 * Forgets everything.
 *
 * Called on sign-out and on profile switch. A mirror is scoped to the profile
 * that synced it — it contains exactly what that profile may see — so carrying
 * it across a switch would show a capped profile the previous one's library.
 */
export async function clear() {
    await transaction(ITEM_STORE, 'readwrite', (store) => store.clear());
    await transaction(META_STORE, 'readwrite', (store) => store.clear());
}

export { DB_NAME, DB_VERSION };
