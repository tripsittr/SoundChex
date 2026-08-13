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

// Runs on first load and again after every swap, because the incoming markup
// is new and has never been through this.
document.addEventListener('livewire:navigated', () => enableNavigation());

// livewire:navigated also fires on initial page load, but only once Livewire
// has booted. This covers the window before that.
enableNavigation();

export { enableNavigation };
