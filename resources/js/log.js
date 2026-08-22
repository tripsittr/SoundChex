/**
 * Recording what went wrong, from anywhere.
 *
 * The app runs on a phone that is not in the room, so a failure nobody wrote
 * down is a failure reported as "it didn't work". The IndexedDB connection bug
 * was only ever diagnosed because an unhandled rejection happened to be
 * captured — 27 of them stacked behind one `UnknownError` — and downloads
 * themselves recorded nothing at all until it was added deliberately.
 *
 * Kept separate from diagnostics.js so a module can log without importing the
 * panel, the device id and the reporting queue along with it.
 */

/**
 * Records an event, if anything is listening.
 *
 * Never throws. A module that cannot log must still do its job, and a
 * diagnostics failure that breaks a download would be worse than the silence
 * it was meant to fix.
 *
 * @param {string} kind  Specific, and `:failed` by convention when something
 *                       broke — that suffix is what makes it reportable.
 * @param {object} detail Whatever identifies the thing being acted on.
 */
export function log(kind, detail = {}) {
    try {
        window.soundchexDiagnostics?.record?.(kind, detail);
    } catch {
        // Reporting is best-effort by definition.
    }
}

/**
 * Records a caught error in a shape that is useful later.
 *
 * `String(error)` alone loses the name, and the name is what separates "the
 * user navigated away" from "the database is gone" — an AbortError is not a
 * fault and should not read like one.
 */
export function logFailure(kind, error, detail = {}) {
    log(kind, {
        ...detail,
        name: error?.name ?? '',
        reason: String(error?.message ?? error).slice(0, 200),
        // Worth separating: an abort is usually the user's own doing.
        aborted: error?.name === 'AbortError',
        // Whether the device thought it had a connection when this happened.
        // navigator.onLine only knows about the interface, which is exactly why
        // it is recorded rather than trusted.
        online: navigator.onLine !== false,
    });
}

/**
 * Wraps a fetch so a failure says which request failed and how.
 *
 * A bare fetch that rejects gives "TypeError: Load failed" with no URL
 * attached, which is what "Could not reach the library" was built on.
 */
export async function loggedFetch(kind, url, options = {}) {
    const started = performance.now();

    try {
        const response = await fetch(url, options);

        if (!response.ok) {
            log(`${kind}:failed`, {
                url: String(url),
                status: response.status,
                ms: Math.round(performance.now() - started),
            });
        }

        return response;
    } catch (error) {
        logFailure(`${kind}:failed`, error, {
            url: String(url),
            ms: Math.round(performance.now() - started),
        });

        throw error;
    }
}
