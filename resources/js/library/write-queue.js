// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Writes made while offline, replayed when the server comes back.
 *
 * Only for things the device can legitimately decide on its own: where you got
 * to in a film, whether something is on the watchlist, a star rating. Anything
 * needing the server to answer — a metadata lookup, a subtitle search — is not
 * queued, because a queued request whose result the user is waiting for is
 * just a request that failed slowly.
 *
 * Stored in IndexedDB rather than memory: the point is to survive the app
 * being closed, which is exactly when a phone reclaims a suspended page.
 */
const DB_NAME = 'soundchex-writes';
const DB_VERSION = 1;
const STORE = 'queue';

/** Past this many failures a write is dropped rather than retried forever. */
const MAX_ATTEMPTS = 5;

let dbPromise = null;

function openDatabase() {
    if (dbPromise) return dbPromise;

    // Captured so a close event from an old connection cannot drop a newer one.
    let pending;

    pending = dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'key' });
            }
        };

        request.onsuccess = () => {
            const db = request.result;

            // iOS closes an IndexedDB connection out from under the page when
            // it is backgrounded or memory is tight, and a cached handle then
            // throws "the database connection is closing" on every transaction
            // for the rest of the session.
            //
            // The downloads and mirror databases were given this treatment when
            // the phone first reported it; this one was missed, and kept
            // producing the same unhandled rejection from a third database
            // nobody had looked at.
            db.onclose = () => {
                if (dbPromise === pending) dbPromise = null;
            };

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

async function transaction(mode, fn, retrying = false) {
    const db = await openDatabase();

    if (! retrying) {
        try {
            return await runTransaction(db, mode, fn);
        } catch (error) {
            // A connection that died between opening and using it, which no
            // close handler can catch: nothing has told the page yet.
            const name = error?.name ?? '';

            if (name === 'InvalidStateError' || name === 'UnknownError'
                || String(error?.message ?? '').includes('connection is closing')) {
                dbPromise = null;

                return transaction(mode, fn, true);
            }

            throw error;
        }
    }

    return runTransaction(db, mode, fn);
}

function runTransaction(db, mode, fn) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const result = fn(tx.objectStore(STORE));

        tx.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/**
 * Adds a write, collapsing repeats of the same thing.
 *
 * The key is what makes this safe to replay. Progress on one item overwrites
 * the previous entry for that item rather than queueing hundreds of positions
 * from a single film — only the last one is true, and replaying the earlier
 * ones would rewind the user.
 */
export async function enqueue({ kind, url, method = 'POST', body = {}, key = null }) {
    const entry = {
        key: key ?? `${kind}:${url}`,
        kind,
        url,
        method,
        body,
        // Sent with the request so the server can reject a write that is older
        // than what it already holds.
        recorded_at: new Date().toISOString(),
        attempts: 0,
    };

    await transaction('readwrite', (store) => store.put(entry));

    return entry;
}

export async function pending() {
    return (await transaction('readonly', (store) => store.getAll())) ?? [];
}

export async function count() {
    return (await transaction('readonly', (store) => store.count())) ?? 0;
}

export async function clear() {
    await transaction('readwrite', (store) => store.clear());
}

async function remove(key) {
    await transaction('readwrite', (store) => store.delete(key));
}

async function recordFailure(entry) {
    const attempts = (entry.attempts ?? 0) + 1;

    // Dropped rather than retried forever. A write that has failed five times
    // is not going to succeed, and an unbounded queue would grow until it
    // filled the device's storage.
    if (attempts >= MAX_ATTEMPTS) {
        await remove(entry.key);

        return;
    }

    await transaction('readwrite', (store) => store.put({ ...entry, attempts }));
}

/**
 * Sends everything queued.
 *
 * Serial rather than parallel: these are writes to the same few rows, and
 * firing them at once invites the server to apply them out of order — which
 * for progress means the oldest position could land last.
 */
export async function flush() {
    const entries = await pending();

    if (entries.length === 0) return { sent: 0, failed: 0 };

    let sent = 0;
    let failed = 0;

    for (const entry of entries) {
        try {
            const response = await fetch(entry.url, {
                method: entry.method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ ...entry.body, recorded_at: entry.recorded_at }),
            });

            if (response.ok) {
                await remove(entry.key);
                sent += 1;

                continue;
            }

            // A rejected write is not a failed one: 4xx means the server
            // understood and refused — a deleted item, or a write the profile
            // may no longer make. Retrying cannot change that answer.
            if (response.status >= 400 && response.status < 500) {
                await remove(entry.key);
                failed += 1;

                continue;
            }

            await recordFailure(entry);
            failed += 1;
        } catch {
            // Still offline. Left in place to try again.
            await recordFailure(entry);
            failed += 1;
        }
    }

    return { sent, failed };
}

export { DB_NAME, MAX_ATTEMPTS };
