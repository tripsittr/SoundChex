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
let running = false;

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
        const entry = waiting.shift();

        active = entry.item;
        announce('started', { item: entry.item, remaining: waiting.length });

        try {
            const result = await run(entry.item);

            entry.resolve(result);
            announce('finished', { item: entry.item, remaining: waiting.length });
        } catch (error) {
            entry.reject(error);
            announce('failed', { item: entry.item, error, remaining: waiting.length });
        } finally {
            active = null;
        }
    }

    running = false;
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

    waiting.push({ item: { ...item, id: String(item.id) }, resolve, reject });

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
    announce('idle', {});
}
