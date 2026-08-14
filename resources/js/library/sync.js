import * as mirror from './mirror.js';

/**
 * Keeping the device's copy in step with the server.
 *
 * Two shapes: a full pull when there is nothing to build on, and a delta after
 * that. The delta sends what the device holds so the server can say what to
 * drop — deletion and a tightened rating cap arrive through the same list,
 * because the device cannot tell them apart and must act identically on both.
 */

/** Where the token lives. Same key the shell writes at sign-in. */
const TOKEN_KEY = 'soundchex.token';

export function token() {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        // Private browsing denies storage; the app still works online.
        return null;
    }
}

export function setToken(value) {
    try {
        if (value === null) {
            localStorage.removeItem(TOKEN_KEY);
        } else {
            localStorage.setItem(TOKEN_KEY, value);
        }
    } catch {
        // Nothing to do: an unstored token means signing in again next launch.
    }
}

async function request(path, { method = 'GET', body = null, headers = {} } = {}) {
    const bearer = token();

    const response = await fetch(path, {
        method,
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(bearer ? { Authorization: `Bearer ${bearer}` } : {}),
            ...headers,
        },
        body: body ? JSON.stringify(body) : null,
    });

    return response;
}

/**
 * Brings the mirror up to date, choosing the cheaper route.
 *
 * Returns what happened so a caller can tell "nothing changed" from "synced
 * 40 items" — a UI that reports success identically in both cases teaches the
 * user to distrust it.
 */
export async function sync({ force = false } = {}) {
    if (!token()) return { status: 'unauthenticated' };

    const since = force ? null : await mirror.syncedAt();

    try {
        return since ? await delta(since) : await full();
    } catch (error) {
        // Offline is the expected case, not an error worth surfacing: the
        // mirror is still valid and the app carries on with what it has.
        return { status: 'offline', error: String(error?.message ?? error) };
    }
}

/**
 * The whole catalogue.
 *
 * Sends the stored ETag so an unchanged library costs a 304 rather than a
 * megabyte — which matters on the relay, where every request is a round trip
 * out of the house and back.
 */
async function full() {
    const etag = await mirror.storedEtag();

    const response = await request('/api/v1/library', {
        headers: etag ? { 'If-None-Match': etag } : {},
    });

    if (response.status === 304) {
        return { status: 'unchanged', count: await mirror.count() };
    }

    if (response.status === 401) {
        return { status: 'unauthenticated' };
    }

    if (!response.ok) throw new Error(`Library sync failed (${response.status})`);

    const payload = await response.json();

    const count = await mirror.replaceAll(payload.items ?? [], {
        syncedAt: payload.synced_at ?? null,
        etag: response.headers.get('ETag'),
    });

    return { status: 'synced', count, full: true };
}

/**
 * Only what changed.
 *
 * POST rather than GET because the device sends every id it holds, which is
 * ~1,450 numbers — far past what a query string can carry reliably.
 */
async function delta(since) {
    const known = (await mirror.all()).map((item) => item.id);

    const response = await request('/api/v1/library/delta', {
        method: 'POST',
        body: { since, known_ids: known },
    });

    if (response.status === 401) {
        return { status: 'unauthenticated' };
    }

    if (!response.ok) throw new Error(`Delta sync failed (${response.status})`);

    const payload = await response.json();

    const result = await mirror.applyDelta(
        payload.items ?? [],
        payload.removed_ids ?? [],
        { syncedAt: payload.synced_at ?? null },
    );

    return { status: 'synced', ...result, full: false };
}

/**
 * Signs this device out.
 *
 * The mirror goes with the token. It holds exactly what one profile may see,
 * so leaving it behind would let the next person to open the app browse a
 * library they were never granted — offline, where no server check applies.
 */
export async function signOut() {
    try {
        await request('/api/v1/tokens/current', { method: 'DELETE' });
    } catch {
        // A revoke that never reached the server still has to clear the
        // device: the local copy is the part within reach.
    }

    setToken(null);
    await mirror.clear();
}

/**
 * Re-syncs from scratch, for a profile switch.
 *
 * Not a delta: the new profile may see less than the old one, and a delta
 * would only add. Clearing first is what stops a capped profile inheriting a
 * previous profile's rows.
 */
export async function resyncForProfile() {
    await mirror.clear();

    return sync({ force: true });
}
