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
