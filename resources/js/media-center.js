import { bindDeviceSettings } from './device-settings.js';
import * as downloadQueue from './download-queue.js';
import {
    paintIconDownloadStates,
    setupBatchDownload,
    setupDownloadButton,
    setupIconDownloads,
} from './download-button.js';
import Alpine from 'alpinejs';

/**
 * Media center front-end.
 *
 * The customer catalog is a standalone Blade layout rather than a Filament
 * page, so it ships its own Alpine instance. Filament's own bundle is scoped
 * to /admin and is not loaded here.
 */

/**
 * Top nav: transparent while the hero is in view, solid once scrolled.
 */
Alpine.data('mediaNav', () => ({
    scrolled: false,

    init() {
        this.onScroll();

        // Passive: this only reads scroll position and never calls
        // preventDefault, so it must not block scrolling.
        window.addEventListener('scroll', () => this.onScroll(), { passive: true });
    },

    onScroll() {
        this.scrolled = window.scrollY > 24;
    },
}));

/**
 * Horizontal rail with arrow controls.
 *
 * Arrows are hidden when there's nothing to scroll to in that direction, so a
 * short row doesn't show dead controls.
 */
Alpine.data('rail', () => ({
    atStart: true,
    atEnd: false,

    init() {
        this.$nextTick(() => this.refresh());

        this.$refs.track.addEventListener('scroll', () => this.refresh(), { passive: true });

        // Rows reflow as artwork loads and on resize; re-measure both times.
        new ResizeObserver(() => this.refresh()).observe(this.$refs.track);
    },

    refresh() {
        const el = this.$refs.track;
        if (!el) return;

        const max = el.scrollWidth - el.clientWidth;

        this.atStart = el.scrollLeft <= 4;
        // 4px slack absorbs sub-pixel rounding at the end of the track.
        this.atEnd = max <= 4 || el.scrollLeft >= max - 4;
    },

    scrollBy(direction) {
        const el = this.$refs.track;
        if (!el) return;

        // Page by ~90% of the visible width so a partially-visible card at the
        // edge stays visible after scrolling, giving a sense of continuity.
        el.scrollBy({
            left: direction * el.clientWidth * 0.9,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'auto'
                : 'smooth',
        });
    },
}));

window.Alpine = Alpine;
Alpine.start();

/**
 * Register the service worker so the media center is installable.
 *
 * Service workers require a secure context, which `localhost` counts as — but
 * a plain-HTTP LAN address does not, so this quietly no-ops there rather than
 * throwing.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch(() => {
                // Registration failing shouldn't break the page; the app works
                // fine without offline support.
            });
    });
}

// Offline downloads on the detail page. No-ops where there is no button.
/**
 * Re-bound after every SPA navigation.
 *
 * These attach to elements inside the swapped region, so binding once at parse
 * time would leave the buttons dead on every page after the first. The event
 * fires on initial load too, so one handler covers both cases.
 */
function bindPageScripts() {
    setupDownloadButton();
    setupBatchDownload();
    setupIconDownloads();

    // Face ID and notifications, where the shell can offer them.
    bindDeviceSettings();

    // Exposed for the browser tests, which drive the queue directly rather than
    // racing five real downloads.
    window.soundchexDownloadQueue = downloadQueue;

    // Repainted per navigation: the delegated listener is registered once, but
    // each new page brings rows whose stored state has not been read yet.
    paintIconDownloadStates();
}

document.addEventListener('livewire:navigated', bindPageScripts);

// Covers the window before Livewire boots on a cold load.
bindPageScripts();
