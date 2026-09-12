/**
 * Makes external links work inside the desktop app.
 *
 * A Tauri webview has no tabs, so `target="_blank"` does nothing at all — the
 * click is simply swallowed. In a browser the same link opens a tab, which is
 * why the Integrations page's "Open Lidarr" button looked correct, tested
 * green, and was dead for anyone using the packaged app. Three attempts at
 * fixing the markup missed it because every one of them was tested in a
 * browser.
 *
 * Handing the URL to the OS opens it in the user's real browser, which is what
 * someone clicking "Open Lidarr" wants: Lidarr's own interface, with their
 * bookmarks and sessions, not a second window of this app.
 *
 * Delegated on `document` so it covers links that arrive later — every control
 * on that page is inside a modal Livewire renders on demand.
 */

const isTauri = () => typeof window !== 'undefined'
    && (window.__TAURI_INTERNALS__ !== undefined || window.__TAURI__ !== undefined);

/** Whether a URL leaves this app, rather than navigating within it. */
function isExternal(url) {
    try {
        const target = new URL(url, window.location.href);

        if (!['http:', 'https:'].includes(target.protocol)) return false;

        return target.origin !== window.location.origin;
    } catch {
        return false;
    }
}

/**
 * Opens a URL in the user's own browser.
 *
 * `openUrl` from the opener plugin, which is the documented way to do this:
 * https://v2.tauri.app/plugin/opener/
 *
 * Hand-rolling the IPC call was the previous attempt and it was wrong twice
 * over — the command name was guessed, and the Rust plugin it would have
 * dispatched to was not installed at all. The package and the crate have to
 * agree, and both are now declared.
 *
 * Imported lazily so the browser build never pulls a Tauri module it cannot
 * use; this file only reaches this function inside the app.
 */
async function openExternally(url) {
    try {
        const { openUrl } = await import('@tauri-apps/plugin-opener');

        await openUrl(url);

        return true;
    } catch (error) {
        // Shown, not just recorded.
        //
        // `log()` writes to `window.soundchexDiagnostics`, which the media
        // center sets up and the admin panel never loads — so the first
        // version of this reported failures into nothing at all, and a dead
        // click still produced total silence. That silence cost several wrong
        // diagnoses.
        //
        // Not `window.alert`: wry does not implement the JS dialogs on macOS,
        // so the alert this used to show was itself silent — the ACL denial
        // behind S-125 reached this very line and nobody saw a thing. A DOM
        // banner is the only surface a webview is guaranteed to render.
        const reason = String(error?.message ?? error);

        try {
            const { logFailure } = await import('./log.js');

            logFailure('shell:open-external:failed', error, { url });
        } catch {
            // Best-effort; never let logging take the click.
        }

        showFailureBanner(`Could not open ${url} — ${reason}`);

        return false;
    }
}

/**
 * A failure the person who clicked can actually see.
 *
 * Plain DOM on purpose: this file is loaded into pages that share none of the
 * app's toast machinery, and it must work when everything else is broken —
 * that being the only time it runs.
 */
function showFailureBanner(message) {
    try {
        const banner = document.createElement('div');

        banner.textContent = message;
        banner.setAttribute('role', 'alert');
        banner.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);'
            + 'z-index:2147483647;max-width:90vw;padding:.75rem 1rem;border-radius:.5rem;'
            + 'background:#7f1d1d;color:#fff;font:500 .875rem/1.4 system-ui,sans-serif;'
            + 'box-shadow:0 4px 12px rgba(0,0,0,.4);cursor:pointer';
        banner.addEventListener('click', () => banner.remove());

        document.body.appendChild(banner);

        setTimeout(() => banner.remove(), 10000);
    } catch {
        // A failure to report a failure ends here.
    }
}

/**
 * Exposed so markup can ask for this directly.
 *
 * The delegated handler below covers ordinary links, but a control that *is*
 * about leaving the app reads better saying so than relying on a document-wide
 * listener to notice it.
 */
window.soundchexOpenExternal = (url) => openExternally(url);

export function setupExternalLinks() {
    // Bound everywhere, not only when Tauri is detected.
    //
    // `isTauri()` reads globals that may not be exposed on a remote origin, and
    // gating the listener on it meant that if the check was wrong the handler
    // never bound and the click did nothing — indistinguishable from every
    // other way this has failed. The decision belongs at the point of opening,
    // where a browser falls through to normal link behaviour anyway.
    if (window.__soundchexExternalLinksBound) return;

    window.__soundchexExternalLinksBound = true;

    document.addEventListener('click', (event) => {
        // Explicitly marked first. Livewire morphs the DOM of anything it
        // renders and strips inline handlers, so the behaviour cannot live in
        // an `onclick` — it has to be here, on a listener Livewire never
        // touches, keyed off an attribute that survives the morph.
        const marked = event.target.closest('[data-open-external]');

        if (marked) {
            // In a real browser the anchor already does the right thing —
            // a new tab, middle-click, copy address. Only take over where
            // that does nothing, which is a webview with no tabs.
            if (!isTauri()) return;

            event.preventDefault();

            openExternally(marked.getAttribute('data-open-external') || marked.href);

            return;
        }

        const link = event.target.closest('a[href]');

        if (!link) return;

        const href = link.getAttribute('href');

        if (!href || !isExternal(href)) return;

        // Only once we know it is a link we can act on, so a modifier-click or
        // an in-app link is left entirely alone.
        event.preventDefault();

        openExternally(link.href);
    });
}

setupExternalLinks();
