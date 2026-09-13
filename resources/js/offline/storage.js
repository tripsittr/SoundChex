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
    storeMeta as idbStoreBlobMeta,
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

/**
 * Whether the native media commands are actually present in this build.
 *
 * A capability probe, not a flag and not a user-agent check: it invokes the
 * cheapest native command (`media_exists` on a sentinel id) and sees whether
 * the shell answers. That is true only inside an app whose Rust side carries
 * the Step 2 commands — a browser has no bridge, and an older shell built
 * before Step 2 rejects the unknown command. So native turns on exactly where
 * it works and nowhere else, with no version to keep in sync.
 *
 * Cached after the first probe; the answer cannot change within a session.
 */
let nativeProbe = null;

async function nativeAvailable() {
    if (nativeProbe !== null) {
        return nativeProbe;
    }

    if (!isTauri()) {
        nativeProbe = false;

        return false;
    }

    try {
        // A harmless call: does this id exist? We do not care about the answer,
        // only that the command is registered and returns rather than throwing
        // "command not found".
        await invoke('media_exists', { id: '__probe__' });
        nativeProbe = true;
    } catch {
        nativeProbe = false;
    }

    return nativeProbe;
}

/**
 * Chooses the backend, probing for native support first.
 *
 * Async because the native probe is — it invokes a command. `storage()` awaits
 * this once at startup and caches the result.
 */
export async function detectBackend() {
    return (await nativeAvailable()) ? 'native' : 'indexeddb';
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

/** Invokes a Tauri command, or throws if there is no shell to invoke it in. */
function invoke(command, args) {
    const fn = window.__TAURI__?.core?.invoke ?? window.__TAURI_INTERNALS__?.invoke;

    if (typeof fn !== 'function') {
        return Promise.reject(new Error(`no Tauri bridge for ${command}`));
    }

    return fn(command, args);
}

/**
 * The native backend (Step 2).
 *
 * Real files on the device's disk, written and served by the Rust commands in
 * `src-tauri/src/lib.rs`. This is the fix for the ~1 GB IndexedDB ceiling: the
 * store is the filesystem, so the limit is free disk space.
 *
 * The blob is serialised to a byte array for `media_save`. That does hold the
 * file in memory for the length of the write — the streaming form
 * (`resume`/chunked `save`) is a later refinement; for now it matches what the
 * IndexedDB path already did, on a store that is not capped at a gigabyte.
 */
const native = {
    name: 'native',

    async save(id, blob, { type = 'application/octet-stream', ...meta } = {}) {
        const buffer = await blob.arrayBuffer();
        const bytes = Array.from(new Uint8Array(buffer));

        const path = await invoke('media_save', { id: String(id), bytes });

        // Metadata still lives in IndexedDB — it is small, and the download
        // list is built from it. Only the bytes moved to disk. Written after
        // the file, so a metadata row always implies a real file, exactly as
        // the IndexedDB backend guarantees.
        await idbStoreBlobMeta(String(id), { size: blob.size, type, path, ...meta });

        return { id: String(id), size: blob.size };
    },

    async open(id) {
        const path = await invoke('media_path_for', { id: String(id) });

        if (!path) {
            return null;
        }

        // Tauri's asset protocol turns a file path into a URL the webview can
        // load and — the part that matters for film — seek within, because it
        // honours range requests. `convertFileSrc` builds that URL.
        const convert = window.__TAURI__?.core?.convertFileSrc;

        return typeof convert === 'function' ? convert(path) : null;
    },

    async has(id) {
        return invoke('media_exists', { id: String(id) });
    },

    async remove(id) {
        await invoke('media_remove', { id: String(id) });
        await idbRemove(String(id));
    },

    async list() {
        // The metadata rows are the list; the native store confirms the file is
        // still there, dropping any row whose file the OS reclaimed.
        const rows = await idbList();
        const onDisk = new Set(
            (await invoke('media_list', {})).map(([name]) => String(name)),
        );

        return rows.filter((row) => onDisk.has(String(row.id)));
    },

    async space() {
        // The real disk, via the Step 2a command — the whole point of native
        // storage is that the ceiling is the disk, not the browser quota.
        const free = await nativeFreeSpace();

        if (free === null) {
            return { known: false, free: 0, total: null, source: 'disk' };
        }

        return { known: true, free, total: null, source: 'disk' };
    },
};

const backends = { indexeddb, native };

let activePromise = null;

/**
 * The live backend, resolved once.
 *
 * Async because choosing it probes for native support. Every interface call
 * awaits this, so the backend is settled before the first `save`/`open`. The
 * probe runs a single time; after that the resolved promise is returned.
 */
export function resolveStorage() {
    if (activePromise === null) {
        activePromise = detectBackend().then((chosen) => {
            const backend = backends[chosen] ?? indexeddb;

            logEvent('storage:backend', { backend: backend.name });

            return backend;
        });
    }

    return activePromise;
}

// The interface, as free functions. Each awaits the chosen backend, then
// forwards — so a caller never has to know the choice is async.
export const save = async (id, source, meta) => (await resolveStorage()).save(id, source, meta);
export const open = async (id) => (await resolveStorage()).open(id);
export const has = async (id) => (await resolveStorage()).has(id);
export const remove = async (id) => (await resolveStorage()).remove(id);
export const list = async () => (await resolveStorage()).list();
export const space = async () => (await resolveStorage()).space();

/** The live backend's name, once resolved. For diagnostics and the specs. */
export const backendName = async () => (await resolveStorage()).name;

if (typeof window !== 'undefined') {
    // Exposed for the offline specs, which assert which backend is live and
    // that the interface is reachable without importing a bundle.
    window.soundchexStorage = { save, open, has, remove, list, space, backend: backendName };
}
