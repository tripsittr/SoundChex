// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { watchForBuilds } from './build-watch.js';
import { showDiagnostics, watchForProblems } from './diagnostics.js';
import { bindDeviceSettings } from './device-settings.js';
import { watchForNotifications } from './notifications.js';
import * as downloadQueue from './download-queue.js';
import { bindDownloadQueueUi } from './download-queue-ui.js';
import { setupDownloadedFilter } from './downloaded-filter.js';
import {
    paintIconDownloadStates,
    setupBatchDownload,
    resumeDownloads,
    setupBatchDownloads,
    setupDownloadButton,
    setupIconDownloads,
} from './download-button.js';
// The offline storage interface. Imported for its side effect: it registers
// `window.soundchexStorage` and chooses a backend once, so anything offline
// talks to one seam rather than reaching into IndexedDB directly (Step 1 of
// the offline rebuild). Nothing else routes through it yet — later steps
// migrate the call sites.
import './offline/storage.js';
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

// Only if nothing else has already started one.
//
// Livewire ships its own Alpine, so on an admin page this would be the second
// instance — and Alpine binds no directives when two are running. Every
// x-on:click and wire:click in the panel silently stops working, which presents
// as buttons that render correctly and do nothing rather than as an error.
//
// It happens on an ordinary navigation from /app into /admin, because this
// bundle is still in memory.
if (! window.Alpine) {
    window.Alpine = Alpine;
    Alpine.start();
}

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
    setupBatchDownloads();
    bindDownloadQueueUi();

    // A queue interrupted by a tunnel or by closing the app.
    resumeDownloads();

    // Face ID and notifications, where the shell can offer them.
    bindDeviceSettings();

    // What happened while the app was closed.
    watchForNotifications();

    // Kept so a failure on a device with no console can still be read.
    watchForProblems();

    document.querySelectorAll('[data-show-diagnostics]').forEach((button) => {
        if (button.dataset.bound === 'true') return;

        button.dataset.bound = 'true';
        button.addEventListener('click', showDiagnostics);
    });

    // A deploy, picked up without a reinstall.
    watchForBuilds();

    // Exposed for the browser tests, which drive the queue directly rather than
    // racing five real downloads.
    window.soundchexDownloadQueue = downloadQueue;

    // Repainted per navigation: the delegated listener is registered once, but
    // each new page brings rows whose stored state has not been read yet.
    paintIconDownloadStates();

    // After the repaint, because both read the same IndexedDB and this one
    // decides what to hide and dim from the answer.
    setupDownloadedFilter();
}

document.addEventListener('livewire:navigated', bindPageScripts);

// Covers the window before Livewire boots on a cold load.
bindPageScripts();
