/**
 * SPA navigation for the media center, so audio survives a link click.
 *
 * Rather than tagging every anchor in every view with wire:navigate — which
 * would silently miss any link added later — internal links are opted in here,
 * once, at runtime.
 *
 * Deliberately excluded:
 *
 *   /app/read/*   the reader mounts epub.js or pdf.js against the document
 *   /app/watch/*  the video player owns the viewport
 *   /admin/*      a different application entirely
 *   downloads     a file transfer, not a page
 *
 * Each of those is entered from a link and left with the back button, so a
 * swap buys nothing and risks tearing down a mounted renderer.
 */

const EXCLUDED = [
    /^\/app\/read\//,
    /^\/app\/watch\/[^/]+$/,
    /^\/admin(\/|$)/,
    /^\/logout$/,
];

function shouldNavigate(link) {
    // The now-playing bar's title is an <a> to the item page, but tapping it
    // opens the full-screen player instead. Tagging it would hand the click to
    // Livewire in the capture phase and navigate away before the sheet's own
    // handler ever ran.
    if (link.id === 'np-link') return false;

    // Only same-origin, ordinary links.
    if (link.origin !== window.location.origin) return false;
    if (link.hasAttribute('download') || link.target === '_blank') return false;
    if (link.getAttribute('href')?.startsWith('#')) return false;

    return !EXCLUDED.some((pattern) => pattern.test(link.pathname));
}

function enableNavigation(root = document) {
    root.querySelectorAll('a[href]:not([wire\\:navigate])').forEach((link) => {
        if (shouldNavigate(link)) {
            link.setAttribute('wire:navigate', '');
        }
    });
}

/**
 * Warms a page before it is asked for.
 *
 * Over Tailscale Funnel every request goes out to a public relay and back, so
 * a cold navigation costs ~290ms while a warm one costs ~35ms. The connection
 * is not slow; establishing it is.
 *
 * touchstart fires roughly 100ms before the click that follows it, and a
 * pointer usually rests on a link longer than that before pressing. Fetching
 * on that signal means the response is often already in the HTTP cache by the
 * time the navigation actually runs.
 *
 * Kept cheap deliberately: same-page duplicates are skipped, and a plain fetch
 * is used rather than a speculation-rules API that Safari does not implement.
 */
const prefetched = new Set();

function prefetch(link) {
    if (!link || !shouldNavigate(link)) return;

    const url = link.href;

    if (prefetched.has(url) || url === window.location.href) return;

    prefetched.add(url);

    // Low priority so a prefetch never competes with the page the user is
    // actually looking at — artwork and audio matter more than a guess.
    fetch(url, {
        credentials: 'same-origin',
        priority: 'low',
        headers: { 'X-Prefetch': '1' },
    }).catch(() => {
        // A failed guess is not worth reporting. Forget it so a real click
        // retries rather than trusting a cache entry that never arrived.
        prefetched.delete(url);
    });
}

// Both events, because phones and desktops signal intent differently: a finger
// touches before it taps, a pointer hovers before it clicks.
document.addEventListener('touchstart', (event) => {
    prefetch(event.target.closest?.('a[href]'));
}, { passive: true, capture: true });

document.addEventListener('mouseover', (event) => {
    prefetch(event.target.closest?.('a[href]'));
}, { passive: true, capture: true });

/**
 * Drives the navigation itself.
 *
 * Tagging links with wire:navigate is not enough here. Livewire only binds its
 * own click interception when the page contains a Livewire component, and the
 * media center is plain Blade — so every click fell through to a full page
 * load, which is what stopped the music. Livewire.navigate() works fine when
 * called directly; only the listener was missing.
 *
 * Capture phase, so this runs before any handler that might stop propagation.
 */
document.addEventListener('click', (event) => {
    // Let the browser handle anything the user asked to open differently:
    // new tab, new window, download, or a right-click.
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const link = event.target.closest('a[href]');

    if (!link || !link.hasAttribute('wire:navigate')) return;
    if (!shouldNavigate(link)) return;
    if (typeof window.Livewire?.navigate !== 'function') return;

    event.preventDefault();
    window.Livewire.navigate(link.href);
}, true);

// Runs on first load and again after every swap, because the incoming markup
// is new and has never been through this.
document.addEventListener('livewire:navigated', () => enableNavigation());

// livewire:navigated also fires on initial page load, but only once Livewire
// has booted. This covers the window before that.
enableNavigation();

export { enableNavigation };
