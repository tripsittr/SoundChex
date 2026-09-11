/**
 * One download at a time, in the order they were asked for.
 *
 * Each download button started its own transfer, so tapping five rows opened
 * five concurrent connections to the same server. On a phone that is slower
 * than doing them in turn — the transfers compete for the same bandwidth, none
 * finishes early, and the progress on every button crawls at once. It also
 * makes the failure case worse: a dropped connection can take down all five
 * rather than one.
 *
 * Serial, with the queue exposed so the UI can say what is waiting.
 */
const waiting = [];

let active = null;

/**
 * How many times an item is tried before it counts as failed.
 *
 * Three is enough to ride out a blip without holding a batch up for minutes
 * when something is genuinely wrong.
 */
const MAX_ATTEMPTS = 3;

/** Multiplied by the attempt number, so retries space out rather than repeat. */
const RETRY_DELAY = 1500;
let running = false;

/**
 * Whether the queue is waiting for a connection rather than working.
 *
 * Going offline mid-batch used to fail every remaining item in turn, each
 * costing a timeout, and leave nothing to resume — so a download interrupted by
 * a tunnel was a download that had to be started again from the beginning.
 */
let paused = false;

/** Where the queue is kept, so it survives the app being closed. */
const QUEUE_KEY = 'soundchex.download-queue';

/**
 * Writes the queue down.
 *
 * Only what is needed to resume: the item, not the function that fetches it.
 * The runner is supplied again when the queue is picked up, because a function
 * cannot be stored and should not be trusted from storage if it could.
 */
function persist() {
    try {
        const pendingItems = [
            ...(active ? [active] : []),
            ...waiting.map((entry) => entry.item),
        ];

        if (pendingItems.length === 0) {
            localStorage.removeItem(QUEUE_KEY);

            return;
        }

        localStorage.setItem(QUEUE_KEY, JSON.stringify(pendingItems));
    } catch {
        // Private browsing, or full. The queue still works for this session.
    }
}

/** What was queued when the app last closed. */
export function stored() {
    try {
        const raw = localStorage.getItem(QUEUE_KEY);
        const items = raw ? JSON.parse(raw) : [];

        return Array.isArray(items) ? items : [];
    } catch {
        return [];
    }
}

export function isPaused() {
    return paused;
}

/** How many are queued or in flight. */
export function size() {
    return waiting.length + (active === null ? 0 : 1);
}

/** The item being downloaded right now, or null. */
export function current() {
    return active;
}

/** Everything queued behind the active one. */
export function pending() {
    return waiting.map((entry) => entry.item);
}

export function has(id) {
    const key = String(id);

    return active?.id === key || waiting.some((entry) => entry.item.id === key);
}

function announce(name, detail) {
    document.dispatchEvent(new CustomEvent(`soundchex:download-${name}`, { detail }));
}

async function drain(run) {
    if (running) return;

    running = true;

    while (waiting.length > 0) {
        // Paused rather than failed. Every remaining item would otherwise fail
        // in turn, each costing its own timeout, and the queue would be gone by
        // the time the connection came back.
        if (navigator.onLine === false) {
            paused = true;
            running = false;
            persist();
            announce('paused', { remaining: waiting.length });

            return;
        }

        const entry = waiting.shift();

        active = entry.item;
        persist();
        announce('started', { item: entry.item, remaining: waiting.length });

        try {
            // The entry's own runner, falling back to the drain's for items
            // restored from storage — those were written down without one.
            const result = await (entry.run ?? run)(entry.item);

            entry.resolve(result);
            announce('finished', { item: entry.item, remaining: waiting.length });
        } catch (error) {
            // A failure while the connection is gone is not the item's fault:
            // it goes back to the front of the queue rather than being counted
            // as failed, so nothing is lost to a tunnel.
            if (navigator.onLine === false) {
                waiting.unshift(entry);
                active = null;
                paused = true;
                running = false;
                persist();
                announce('paused', { remaining: waiting.length });

                return;
            }

            // A failure with the connection apparently up is usually still the
            // network: a dropped packet, a moment of bad signal, the server
            // restarting. `navigator.onLine` only knows about the interface,
            // not whether anything is reachable through it, so the offline
            // branch above catches fewer cases than it appears to.
            //
            // Retried a few times before it counts as failed, so one blip in a
            // batch of 400 does not silently lose a track. Abort is not
            // retried: the user asked for it to stop.
            const attempts = (entry.attempts ?? 0) + 1;

            if (error?.name !== 'AbortError' && attempts < MAX_ATTEMPTS) {
                entry.attempts = attempts;

                // Back of the queue, not the front: the rest of the batch
                // should not wait behind one item that is struggling.
                waiting.push(entry);
                active = null;
                persist();
                announce('retrying', { item: entry.item, attempt: attempts, remaining: waiting.length });

                // Widening pause, so a genuinely dead server is not hammered.
                // eslint-disable-next-line no-await-in-loop
                await new Promise((wait) => { setTimeout(wait, RETRY_DELAY * attempts); });

                continue;
            }

            entry.reject(error);
            announce('failed', { item: entry.item, error, attempts, remaining: waiting.length });
        } finally {
            active = null;
            persist();
        }
    }

    running = false;
    persist();
    announce('idle', {});
}

/**
 * Adds a download and returns a promise for when it finishes.
 *
 * The promise settles when that item's turn comes and completes, so a caller
 * can await it exactly as if it had started immediately — the queue is
 * invisible except in when it runs.
 *
 * Duplicates are collapsed: tapping the same track twice while it waits should
 * not download it twice.
 */
export function enqueue(item, run) {
    if (has(item.id)) {
        return { queued: false, position: null, promise: Promise.resolve(null) };
    }

    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });

    // The runner travels with the entry. `drain()` used to take one runner and
    // apply it to everything still queued, so whichever enqueue happened to
    // start the drain decided how *every* later item was fetched — a queue
    // holding two items with different runners ran the first one's twice, and
    // the second download silently never happened.
    waiting.push({ item: { ...item, id: String(item.id) }, run, resolve, reject });

    // Counts the download already in flight. Using waiting.length alone
    // reported position 1 for an item queued behind a running download, because
    // that one has been shifted out of the list — so "is anything ahead of me"
    // was always false and the queue never announced itself.
    const position = waiting.length + (active === null ? 0 : 1);

    announce('queued', { item, position });

    // Started on a microtask rather than inline, so a caller that enqueues
    // several in a row sees them all counted before the first one runs — the
    // position it reports would otherwise always be 1.
    queueMicrotask(() => drain(run));

    return { queued: true, position, promise };
}

/** Empties the queue. The active download is left to finish. */
export function clear() {
    waiting.splice(0).forEach((entry) => entry.resolve(null));
    paused = false;
    persist();
    announce('idle', {});
}

/**
 * Picks the queue back up.
 *
 * Called when the connection returns and when the app opens, since a queue
 * interrupted by closing the app is the same problem as one interrupted by a
 * tunnel. The runner is supplied by the caller rather than stored.
 */
export function resume(run) {
    paused = false;

    // Anything written down but not in memory — the app was closed and
    // reopened, so the queue exists only in storage.
    if (waiting.length === 0 && active === null) {
        stored().forEach((item) => {
            let resolve;
            let reject;
            const promise = new Promise((res, rej) => { resolve = res; reject = rej; });

            // Swallowed: nobody is waiting on a promise from a previous
            // session, and an unhandled rejection would surface as an error the
            // user cannot act on.
            promise.catch(() => {});

            waiting.push({ item, resolve, reject });
        });
    }

    if (waiting.length === 0) return { resumed: 0 };

    announce('resumed', { remaining: waiting.length });
    queueMicrotask(() => drain(run));

    return { resumed: waiting.length };
}
