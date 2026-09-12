import { log as logEvent } from '../log.js';

/**
 * The one interface everything talks to for offline files.
 *
 * The download button, the queue, the player and the shell go through this and
 * never learn which backend is live. That is the whole point: today it is
 * IndexedDB, on a device it becomes native files (Step 2), and neither side has
 * to know. Capability detection chooses once at startup — not user-agent, so a
 * browser tab keeps working and a phone gets the real thing.
 *
 * The interface (from `OfflineRebuild.md`, Step 1):
 *
 *   save(id, source, { type, bytes })  store a file
 *   open(id)                           a URL the player can seek in, or null
 *   has(id)                            whether it is genuinely stored
 *   remove(id)                         delete it
 *   list()                             metadata for everything stored
 *   space()                            real free bytes, and whether that is known
 *
 * `resume()` and a streaming `save()` are what films and books will add; they
 * belong to the native backend and are declared on the contract now so the
 * shape does not change under callers when that lands.
 *
 * This module owns the *interface*. The `indexeddb` backend below delegates to
 * the proven store operations in `../downloads.js` rather than reimplementing
 * them, so today's behaviour — including the Safari ArrayBuffer workaround — is
 * preserved exactly while the seam is established.
 */

import {
    localUrl as idbOpen,
    isDownloaded as idbHas,
    remove as idbRemove,
    list as idbList,
    storageEstimate,
    storeBlob as idbStoreBlob,
} from '../downloads.js';

/**
 * Which backend the environment can support.
 *
 * Native storage exists only inside the app, where Tauri's commands can write
 * real files. Detected by the Tauri globals, the same way `device-settings.js`
 * and `external-links.js` decide — capability, not user-agent.
 */
function isTauri() {
    return typeof window !== 'undefined'
        && (window.__TAURI_INTERNALS__ !== undefined || window.__TAURI__ !== undefined);
}

export function detectBackend() {
    // Native *storage* is not built yet (Step 2), so even inside Tauri we store
    // in IndexedDB for now. The detection is here so that turning it on is a
    // one-line change rather than a new decision spread across call sites.
    const nativeReady = false;

    return isTauri() && nativeReady ? 'native' : 'indexeddb';
}

/**
 * Real free disk bytes from the shell, or null outside the app.
 *
 * The `free_space` Tauri command (Step 2a) answers this from `statvfs` and
 * matches `df` to the byte. It is available even while storage is still
 * IndexedDB, so the *number* the space gate reads becomes real on the phone
 * ahead of the storage backend itself — the browser quota was the wrong source
 * regardless of where the bytes land.
 *
 * @returns {Promise<number|null>}
 */
async function nativeFreeSpace() {
    if (!isTauri()) {
        return null;
    }

    try {
        const invoke = window.__TAURI__?.core?.invoke ?? window.__TAURI_INTERNALS__?.invoke;

        if (typeof invoke !== 'function') {
            return null;
        }

        // The app's own storage volume. '/' is a safe stand-in for "the disk
        // this app writes to" on the platforms that have one root; the native
        // storage backend will pass its actual media directory at Step 2.
        const bytes = await invoke('free_space', { path: '/' });

        return typeof bytes === 'number' && bytes >= 0 ? bytes : null;
    } catch {
        // No shell, or the command is not registered on this build. Fall back
        // to the quota rather than failing the gate.
        return null;
    }
}

/**
 * The IndexedDB backend.
 *
 * A thin adapter over `downloads.js`. It exists so that the interface has one
 * concrete implementation today, and so the shape the native backend must match
 * is written down and exercised.
 */
const indexeddb = {
    name: 'indexeddb',

    async save(id, blob, { type = 'application/octet-stream' } = {}) {
        // `downloads.js` owns the write, including the ArrayBuffer conversion
        // Safari needs. Bytes are the blob itself here; the streaming form
        // arrives with the native backend.
        return idbStoreBlob(String(id), blob, { type });
    },

    open(id) {
        return idbOpen(String(id));
    },

    has(id) {
        return idbHas(String(id));
    },

    remove(id) {
        return idbRemove(String(id));
    },

    list() {
        return idbList();
    },

    /**
     * Free space, and whether the number is real.
     *
     * IndexedDB can only offer the browser *quota* — a slice the engine set
     * aside, not the device's disk, and on iOS bounded by the ~1 GB cap. So
     * this reports `known: false` when it cannot do better, and the caller
     * decides rather than being handed a confident wrong number. The native
     * backend answers this from `statvfs` and sets `known: true`.
     */
    async space() {
        // The real disk first, when the shell can answer. This is the fix for
        // the space gate reading the browser quota (bounded near 1 GB on iOS)
        // instead of the tens of gigabytes actually free — the number is wrong
        // wherever the bytes are stored, so it is corrected here even while
        // storage is still IndexedDB.
        const disk = await nativeFreeSpace();

        if (disk !== null) {
            return { known: true, free: disk, total: null, source: 'disk' };
        }

        const estimate = await storageEstimate();

        if (!estimate || !estimate.quota) {
            return { known: false, free: 0, total: 0, source: 'quota' };
        }

        return {
            known: true,
            free: estimate.free,
            total: estimate.quota,
            source: 'quota',
        };
    },
};

/** The native backend, filled in at Step 2. Declared so the contract is whole. */
const native = {
    name: 'native',
    save() { throw new Error('native storage is not built yet (Step 2)'); },
    open() { throw new Error('native storage is not built yet (Step 2)'); },
    has() { throw new Error('native storage is not built yet (Step 2)'); },
    remove() { throw new Error('native storage is not built yet (Step 2)'); },
    list() { throw new Error('native storage is not built yet (Step 2)'); },
    space() { throw new Error('native storage is not built yet (Step 2)'); },
};

const backends = { indexeddb, native };

let active = null;

/** The live backend, chosen once. */
export function storage() {
    if (active === null) {
        const chosen = detectBackend();
        active = backends[chosen] ?? indexeddb;

        logEvent('storage:backend', { backend: active.name });
    }

    return active;
}

// The interface, as free functions, so callers import what they use rather than
// reaching through an object. Each forwards to the live backend.
export const save = (id, source, meta) => storage().save(id, source, meta);
export const open = (id) => storage().open(id);
export const has = (id) => storage().has(id);
export const remove = (id) => storage().remove(id);
export const list = () => storage().list();
export const space = () => storage().space();

if (typeof window !== 'undefined') {
    // Exposed for the offline specs, which assert which backend is live and
    // that the interface is reachable without importing a bundle.
    window.soundchexStorage = { save, open, has, remove, list, space, backend: () => storage().name };
}
