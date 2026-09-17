// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { logFailure } from './log.js';
import ePub from 'epubjs';
import { AnnotationStore } from './reader/annotations.js';
import { setupAnnotationUi } from './reader/annotation-ui.js';
import { mountPdf } from './reader/pdf.js';
import { setupContents } from './reader/contents.js';

// pdf.js and JSZip are loaded on demand — most books are EPUB, and there's no
// reason to ship a PDF engine and a zip library to open one.

/**
 * Book reader for the media center.
 *
 * The goal is an e-reader, not a document viewer: one column of comfortable
 * text on a warm page, no browser chrome, controls that stay out of the way.
 *
 *   EPUB  epub.js paginates reflowable text; resume position is a CFI.
 *   PDF   rendered to canvas with pdf.js. The browser's built-in viewer was
 *         the obvious shortcut, but its toolbar lives inside an <iframe> and
 *         can't be styled — a grey strip in a dark app. Rendering it here
 *         costs ~344 KB and buys full control of the page.
 *   CBZ   unzipped in the browser to image pages. CBR is RAR and can't be
 *         unpacked client-side, so it downloads instead.
 */

const THEMES = {
    paper: {
        label: 'Paper',
        page: '#faf6ef',
        ink: '#2b2724',
        muted: '#6f675e',
        chrome: '#efe8dc',
        // Paper and sepia are light pages, so the surrounding app has to
        // lighten with them or the contrast is jarring at the edges.
        surround: '#e7dfd1',
    },
    sepia: {
        label: 'Sepia',
        page: '#f2e5cd',
        ink: '#3b2f21',
        muted: '#7a6748',
        chrome: '#e6d6b8',
        surround: '#dbc9a6',
    },
    night: {
        label: 'Night',
        page: '#12121a',
        ink: '#d8d8dd',
        muted: '#8a8a96',
        chrome: '#1b1b24',
        surround: '#08080b',
    },
};

const FONT_SIZES = [90, 100, 110, 125, 140, 160];

/** Highlight fills, keyed to match the stored colour names. */
const HIGHLIGHT_INK = {
    yellow: '#f5c518',
    green: '#5bc47a',
    blue: '#5aa9e6',
    pink: '#e87fa8',
};

const PREFS_KEY = 'soundchex.reader.prefs';

const el = document.getElementById('reader');

if (el) {
    const config = {
        format: el.dataset.format,
        fileUrl: el.dataset.fileUrl,
        progressUrl: el.dataset.progressUrl,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content,
        startLocation: el.dataset.location || null,
    };

    const prefs = loadPrefs();
    const status = document.getElementById('reader-status');
    const percentLabel = document.getElementById('reader-percent');

    // Templated with __PAGE__ so the PDF module can fill in whichever page it
    // is rendering without knowing how the route is shaped.
    config.ocrUrl = el.dataset.ocrUrl ?? null;
    config.contentsUrl = el.dataset.contentsUrl ?? null;
    config.searchUrl = el.dataset.searchUrl ?? null;
    config.readingTextUrl = el.dataset.readingTextUrl ?? null;

    applyTheme(prefs.theme);

    const saveProgress = debounce((location, percent) => {
        if (!config.progressUrl) return;

        // Stamped here rather than when the queue drains: what matters is when
        // the page was actually read, not when the connection came back.
        const recordedAt = new Date().toISOString();

        fetch(config.progressUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrf ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ location, percent, recorded_at: recordedAt }),
            // Lets a save in flight finish if the tab is closing.
            keepalive: true,
        }).catch((error) => {
            // Queued rather than dropped. The player has done this for a while
            // — the position of a film someone is watching on a train — and a
            // book read on the same train was losing its page instead, because
            // this only ever swallowed the failure.
            //
            // Keyed per item so a long reading session leaves one entry to
            // replay rather than one per page turn, and stamped with when it
            // was recorded so the server can refuse it if a newer position has
            // arrived from another device meanwhile.
            window.soundchexWrites?.enqueue({
                kind: 'reading-progress',
                url: config.progressUrl,
                body: { location, percent, recorded_at: recordedAt },
            });

            logFailure('reader:progress:failed', error, {
                percent,
                queued: Boolean(window.soundchexWrites),
            });
        });

        if (percentLabel) percentLabel.textContent = `${percent}%`;
    }, 1200);

    const context = { status, saveProgress, prefs, ocrUrl: config.ocrUrl };

    // Highlights are loaded before the book renders, so the first page paints
    // with its marks already in place rather than flashing them in after.
    if (el.dataset.annotationsUrl) {
        context.annotations = new AnnotationStore(el.dataset.annotationsUrl, config.csrf);
    }

    // The edge tap-zones are for reflowable formats. On a PDF they sit over
    // the text and swallow the drag that starts a selection, so the PDF
    // module supplies its own paging instead.
    if (config.format === 'pdf') {
        document.getElementById('reader-edges')?.remove();
    }

    const readers = { epub: mountEpub, pdf: mountPdf, cbz: mountComic };
    const mount = readers[config.format];

    const start = async () => {
        // A downloaded book opens from the device. Resolved before the
        // renderer mounts, because epub.js and pdf.js both take the URL once
        // and never re-read it.
        config.fileUrl = await resolveLocalFile(el.dataset.itemId, config.fileUrl);

        await context.annotations?.load();

        // Wired before the book renders: the renderer registers selection and
        // highlight handlers as it mounts, and those call back into this. Set
        // up afterwards, the first selection in an EPUB would find nothing
        // listening and silently do nothing.
        if (context.annotations) setupAnnotationUi(context);

        if (mount) {
            await mount(config, context);
        } else if (status) {
            status.textContent = 'This format can only be downloaded.';
        }

        if (config.format === 'pdf') {
            setupPdfControls(context, config);
            setupContents(context, config);
        }
    };

    start();

    setupChrome(context);
    setupChromeReveal();
}

/**
 * The downloaded copy's blob URL, or the network URL unchanged.
 *
 * Unlike audio and video this cannot be swapped in later: the book renderers
 * read the URL once at construction, so the choice has to be made first.
 */
async function resolveLocalFile(itemId, fallback) {
    if (!itemId || !window.indexedDB) return fallback;

    try {
        const { localUrl } = await import('./offline/storage.js');
        const url = await localUrl(itemId);

        if (!url) return fallback;

        // Revoked on unload; a live URL holds the whole file in memory.
        window.addEventListener('pagehide', () => URL.revokeObjectURL(url), { once: true });

        document.getElementById('reader-offline-badge')?.classList.remove('hidden');

        return url;
    } catch {
        return fallback;
    }
}

/* ------------------------------------------------------------------ EPUB */

function mountEpub(config, ctx) {
    const book = ePub(config.fileUrl);
    const rendition = book.renderTo('reader-surface', {
        width: '100%',
        height: '100%',
        // One column reads like a page rather than a spread; wide screens get
        // a capped measure instead, which is easier on the eye than full width.
        spread: 'none',
        flow: 'paginated',
    });

    const applyReadingStyle = () => {
        const theme = THEMES[ctx.prefs.theme];

        rendition.themes.override('color', theme.ink, true);
        rendition.themes.override('background', theme.page, true);
        rendition.themes.fontSize(`${ctx.prefs.fontSize}%`);
        rendition.themes.override('line-height', String(ctx.prefs.lineHeight), true);
        rendition.themes.font(ctx.prefs.serif ? 'Georgia, serif' : 'inherit');
    };

    rendition.hooks.content.register((contents) => {
        // Injected into the book's own document, which the outer page's CSS
        // can't reach.
        contents.addStylesheetRules({
            'a, a:visited': { color: 'inherit', 'text-decoration': 'underline' },
            img: { 'max-width': '100%', height: 'auto' },
            p: { 'text-align': 'justify', hyphens: 'auto' },
        });
    });

    rendition.display(config.startLocation || undefined).then(() => {
        applyReadingStyle();
        ctx.status?.remove();
    });

    // Percentages need generated locations; it's async and slow on big books.
    book.ready.then(() => book.locations.generate(1600)).catch(() => undefined);

    rendition.on('relocated', (location) => {
        const cfi = location.start.cfi;
        const percent = book.locations.length()
            ? Math.round(book.locations.percentageFromCfi(cfi) * 100)
            : 0;

        ctx.saveProgress(cfi, percent);

        // Marks live in the book's iframe and are lost when a page re-renders,
        // so they're re-applied on every relocation.
        paintEpubHighlights();
    });

    /* ------------------------------------------------------ annotations */

    // The book renders inside an iframe, so the outer document's selection is
    // always empty. epub.js surfaces the inner one through this event, and a
    // CFI range is what locates it — the EPUB equivalent of the PDF's rects.
    let pendingEpub = null;

    rendition.on('selected', (cfiRange, contents) => {
        const text = contents.window.getSelection()?.toString().trim() ?? '';

        if (!text) return;

        pendingEpub = {
            page: null,
            excerpt: text.slice(0, 2000),
            location: { cfi: cfiRange },
        };

        // A pointerup inside the iframe never reaches the outer document, so
        // the popover has to be asked for here rather than waiting for one.
        ctx.requestSelectionPopover?.();
    });

    ctx.captureSelection = () => {
        if (!pendingEpub) return null;

        const captured = pendingEpub;
        pendingEpub = null;

        // Anchored to the rendered range so the popover lands on the passage.
        const range = rendition.getRange(captured.location.cfi);
        const rect = range?.getBoundingClientRect();
        const frame = rendition.getContents()[0]?.document?.defaultView?.frameElement;
        const offset = frame?.getBoundingClientRect() ?? { left: 0, top: 0 };

        return {
            ...captured,
            anchor: rect
                ? {
                    left: rect.left + offset.left,
                    top: rect.top + offset.top,
                    bottom: rect.bottom + offset.top,
                    width: rect.width,
                    height: rect.height,
                }
                : { left: window.innerWidth / 2, top: window.innerHeight / 2, bottom: 0, width: 0, height: 0 },
        };
    };

    // CFIs currently drawn on the rendition. Repainting only walks the
    // annotations that still exist, so without this a deleted highlight would
    // stay on the page until the book was reopened.
    const painted = new Set();

    const paintEpubHighlights = () => {
        const annotations = ctx.annotations?.items ?? [];
        const live = new Set(
            annotations.map((a) => a.location?.cfi).filter(Boolean),
        );

        painted.forEach((cfi) => {
            if (live.has(cfi)) return;

            try {
                rendition.annotations.remove(cfi, 'highlight');
            } catch {
                // Already gone with its page.
            }

            painted.delete(cfi);
        });

        annotations.forEach((annotation) => {
            const cfi = annotation.location?.cfi;

            if (!cfi) return;

            try {
                // Re-adding the same CFI throws; removing first keeps this
                // idempotent across re-renders.
                rendition.annotations.remove(cfi, 'highlight');
            } catch {
                // Not previously applied — nothing to remove.
            }

            try {
                rendition.annotations.highlight(
                    cfi,
                    {},
                    () => ctx.onHighlightClick?.(annotation, { getBoundingClientRect: () => rendition.getRange(cfi)?.getBoundingClientRect() ?? {} }),
                    undefined,
                    { fill: HIGHLIGHT_INK[annotation.color] ?? HIGHLIGHT_INK.yellow },
                );

                painted.add(cfi);
            } catch {
                // A CFI that no longer resolves (a re-generated EPUB) is
                // skipped rather than breaking the whole page.
            }
        });
    };

    ctx.redrawHighlights = paintEpubHighlights;
    ctx.annotations?.onChange(paintEpubHighlights);

    ctx.onPrefsChange = applyReadingStyle;
    ctx.prev = () => rendition.prev();
    ctx.next = () => rendition.next();

    bindPaging(ctx);
}

/* ------------------------------------------------------------------ CBZ */

async function mountComic(config, ctx) {
    const surface = document.getElementById('reader-surface');

    try {
        const { default: JSZip } = await import('jszip');

        const response = await fetch(config.fileUrl);
        const zip = await JSZip.loadAsync(await response.arrayBuffer());

        // Archive order isn't guaranteed, so sort by filename — how comic
        // pages are conventionally numbered.
        const pages = Object.values(zip.files)
            .filter((f) => !f.dir && /\.(jpe?g|png|webp|gif)$/i.test(f.name))
            .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));

        if (pages.length === 0) {
            if (ctx.status) ctx.status.textContent = 'No images found in this archive.';
            return;
        }

        const urls = await Promise.all(
            pages.map(async (page) => URL.createObjectURL(await page.async('blob'))),
        );

        let index = clamp(parseInt(config.startLocation ?? '0', 10) || 0, 0, urls.length - 1);

        const img = document.createElement('img');
        img.className = 'mx-auto max-h-full w-auto object-contain';
        img.alt = '';
        surface.replaceChildren(img);
        ctx.status?.remove();

        const render = () => {
            img.src = urls[index];
            ctx.saveProgress(String(index), Math.round(((index + 1) / urls.length) * 100));
        };

        const step = (delta) => {
            index = clamp(index + delta, 0, urls.length - 1);
            render();
        };

        ctx.prev = () => step(-1);
        ctx.next = () => step(1);

        bindPaging(ctx);
        render();
    } catch (error) {
        // The message blamed CBR for everything, so a corrupt zip, a failed
        // fetch and an out-of-memory all read as "this is a RAR file" — which
        // is wrong three times out of four and sends the reader after the
        // wrong fix.
        logFailure('reader:archive:failed', error, { item: ctx.itemId ?? null });

        if (ctx.status) {
            ctx.status.textContent = error?.name === 'AbortError'
                ? 'Opening this book was interrupted.'
                : 'Could not open this archive. CBR (RAR) files must be downloaded rather than read here.';
        }
    }
}

/* ----------------------------------------------------------- PDF chrome */

/**
 * Controls that only make sense for a fixed-layout document: how the page is
 * fitted, zoom, jumping to a page number, and the reflowable text toggle.
 *
 * The fit mode is what normalises differently-shaped PDFs — it's a preference
 * of the reader's, not of the file's, so it persists across books.
 */
function setupPdfControls(ctx, config) {
    // Paging buttons in the footer bar.
    document.getElementById('reader-prev-btn')?.addEventListener('click', () => ctx.prev?.());
    document.getElementById('reader-next-btn')?.addEventListener('click', () => ctx.next?.());

    // Fit mode
    document.querySelectorAll('[data-pdf-fit]').forEach((button) => {
        button.addEventListener('click', () => {
            ctx.prefs.pdfFit = button.dataset.pdfFit;
            // Switching fit mode resets zoom: "fit width at 220%" is almost
            // never what someone wants after deliberately changing the fit.
            ctx.prefs.pdfZoom = 1;
            savePrefs(ctx.prefs);
            ctx.onPrefsChange?.();
            markActive('[data-pdf-fit]', 'pdfFit', ctx.prefs.pdfFit);
            updateZoomLabel(ctx);
        });
    });

    markActive('[data-pdf-fit]', 'pdfFit', ctx.prefs.pdfFit);

    // Zoom. Shared by the buttons and ⌘/ctrl-wheel.
    ctx.adjustZoom = (delta) => {
        ctx.prefs.pdfZoom = clamp(
            Math.round(((ctx.prefs.pdfZoom ?? 1) + delta) * 20) / 20,
            0.5,
            5,
        );
        savePrefs(ctx.prefs);
        ctx.onPrefsChange?.();
        updateZoomLabel(ctx);
    };

    document.getElementById('reader-zoom-in')?.addEventListener('click', () => ctx.adjustZoom(0.1));
    document.getElementById('reader-zoom-out')?.addEventListener('click', () => ctx.adjustZoom(-0.1));

    document.getElementById('reader-zoom-reset')?.addEventListener('click', () => {
        ctx.prefs.pdfZoom = 1;
        savePrefs(ctx.prefs);
        ctx.onPrefsChange?.();
        updateZoomLabel(ctx);
    });

    updateZoomLabel(ctx);

    // Page jump
    const pageInput = document.getElementById('reader-page-input');
    const pageTotal = document.getElementById('reader-page-total');

    ctx.onPageRendered = (page, total) => {
        if (pageInput && document.activeElement !== pageInput) {
            pageInput.value = String(page);
        }

        if (pageTotal) pageTotal.textContent = String(total);
        if (pageInput) pageInput.max = String(total);
    };

    pageInput?.addEventListener('change', () => {
        const target = parseInt(pageInput.value, 10);

        if (!Number.isNaN(target)) ctx.goToPage?.(target);
    });

    setupTextMode(ctx, config);
    setupOcrStatus(ctx);
}

/**
 * Tells the reader what's happening on a scanned page.
 *
 * Without this a scan looks broken — selection does nothing and there's no
 * indication why. The banner names the state and clears itself once the page
 * becomes selectable.
 */
function setupOcrStatus(ctx) {
    const banner = document.getElementById('reader-ocr-status');

    if (!banner) return;

    const show = (message, persistent = false) => {
        banner.textContent = message;
        banner.classList.remove('hidden');

        clearTimeout(banner.dataset.timer);

        if (!persistent) {
            banner.dataset.timer = setTimeout(() => banner.classList.add('hidden'), 4000);
        }
    };

    // Success is silent. Announcing that recognition worked interrupts reading
    // to report a feature doing its job — the reader finds out by selecting
    // the text, which is the only thing they wanted.
    ctx.onOcrReady = () => {};

    ctx.onOcrUnavailable = (page, data) => {
        // Only states the reader can do something about. A page that had its
        // own text ('skipped'), was blank, or simply failed is not worth a
        // banner — it explains nothing they can act on.
        const messages = {
            unavailable: 'Selecting text on scanned pages needs Tesseract installed on the server.',
            disabled: 'Turn on text recognition in Library settings to select text on scanned pages.',
        };

        const message = messages[data.status];

        if (!message) return;

        show(message, true);
    };
}

/**
 * The opt-in reflowable view.
 *
 * Loaded lazily — the extraction code is only worth downloading if someone
 * actually turns this on.
 */
function setupTextMode(ctx, config) {
    const toggle = document.getElementById('reader-textmode-toggle');
    const panel = document.getElementById('reader-textmode');
    const body = document.getElementById('reader-textmode-content');
    const surface = document.getElementById('reader-surface');

    if (!toggle || !panel || !body) return;

    // Restored from preferences rather than defaulting off: someone who reads
    // reflowed wants that on every book, and having it reset on every refresh
    // means re-enabling it constantly.
    let active = Boolean(ctx.prefs.textMode);
    let flow = null;

    /**
     * Applies zoom as text size.
     *
     * The zoom control scales the canvas, which text mode hides — so pressing
     * it appeared to do nothing. In a reflowed column the equivalent of
     * zooming is making the type bigger, and it shares pdfZoom so the two
     * views agree about how large "120%" is.
     */
    const applyFlowZoom = () => {
        if (!panel) return;

        const scale = ctx.prefs.pdfZoom ?? 1;

        // Clamped tighter than canvas zoom: past these the measure collapses
        // to a few words a line, or the type is too small to read.
        panel.style.setProperty(
            '--flow-scale',
            String(Math.min(2, Math.max(0.7, scale))),
        );
    };

    const canvasAdjustZoom = ctx.adjustZoom;

    ctx.adjustZoom = (delta) => {
        canvasAdjustZoom?.(delta);

        if (active) applyFlowZoom();
    };

    applyFlowZoom();

    // Page jumps have to reach whichever view is on screen. ctx.goToPage
    // drives the canvas, which in text mode is hidden — so a jump re-rendered
    // something invisible while the column stayed where it was.
    const canvasGoToPage = ctx.goToPage;

    ctx.goToPage = (page) => {
        if (active && flow) {
            flow.goTo(page);

            // Kept in step so switching back to the page view lands in the
            // same place.
            canvasGoToPage?.(page);

            return;
        }

        canvasGoToPage?.(page);
    };

    // Paging buttons move the canvas by one page. In a continuous column that
    // is meaningless — scrolling is the navigation — so they step the flow
    // instead, keeping the canvas in sync underneath.
    const canvasPrev = ctx.prev;
    const canvasNext = ctx.next;

    ctx.prev = () => {
        if (active && flow) return ctx.goToPage(Math.max(1, (ctx.currentPage?.() ?? 1) - 1));

        canvasPrev?.();
    };

    ctx.next = () => {
        if (active && flow) return ctx.goToPage((ctx.currentPage?.() ?? 1) + 1);

        canvasNext?.();
    };

    /**
     * Shows or hides the reflowed column.
     *
     * Shared by the toggle and by the restore on load, so the two can't drift
     * apart — a restored state that only flipped the switch would leave the
     * canvas visible under an empty flow.
     */
    const apply = async (on, startPage) => {
        active = on;

        panel.classList.toggle('hidden', !on);
        surface?.classList.toggle('hidden', on);
        toggle.setAttribute('aria-checked', on ? 'true' : 'false');

        if (!on) return;

        applyFlowZoom();

        if (!flow) {
            const { createTextMode } = await import('./reader/text-mode.js');
            flow = createTextMode(config, ctx);
        }

        // Picks up where the page view was, so switching doesn't lose your
        // place.
        await flow?.show(startPage ?? ctx.currentPage?.() ?? 1);
    };

    toggle.addEventListener('click', async () => {
        await apply(!active);

        ctx.prefs.textMode = active;
        savePrefs(ctx.prefs);
    });

    // Restored on load. Deferred so the PDF has finished its first render and
    // reported a page — starting the flow before that would begin at page 1
    // rather than where the reader left off.
    if (active) {
        const resume = parseInt(config.startLocation ?? '1', 10) || 1;

        // The switch is set immediately so it never shows the wrong state
        // while the column is being built.
        toggle.setAttribute('aria-checked', 'true');

        requestAnimationFrame(() => apply(true, resume));
    }

    // Scrolling the continuous column moves the page number and progress with
    // it, so the two views stay in step.
    // Which page the reader is actually looking at. Scrolling the column
    // doesn't move the canvas, so its own page counter goes stale the moment
    // text mode is used — prev/next and the input both need this instead.
    let flowPage = null;

    const canvasCurrentPage = ctx.currentPage;

    ctx.currentPage = () => (active && flowPage !== null
        ? flowPage
        : (canvasCurrentPage?.() ?? 1));

    ctx.onFlowPage = (page) => {
        flowPage = page;

        const input = document.getElementById('reader-page-input');

        if (input && document.activeElement !== input) input.value = String(page);

        // Scrolling the column is reading, so it has to record progress the
        // same way turning a page does — otherwise text mode always resumes
        // from wherever the canvas happened to stop.
        const total = ctx.pageCount?.() ?? 0;

        if (total > 0) {
            ctx.saveProgress(String(page), Math.round((page / total) * 100));
        }
    };
}

function updateZoomLabel(ctx) {
    const label = document.getElementById('reader-zoom-label');

    if (label) label.textContent = `${Math.round((ctx.prefs.pdfZoom ?? 1) * 100)}%`;
}

/* --------------------------------------------------------------- chrome */

/**
 * Paging by arrow key, on-screen buttons, and tapping the page edges — the
 * last is how every e-reader works and the only comfortable option one-handed.
 */
function bindPaging(ctx) {
    document.getElementById('reader-prev')?.addEventListener('click', () => ctx.prev?.());
    document.getElementById('reader-next')?.addEventListener('click', () => ctx.next?.());

    document.addEventListener('keydown', (event) => {
        if (event.target.matches('input, textarea, select')) return;

        if (event.key === 'ArrowLeft' || event.key === 'PageUp') ctx.prev?.();
        if (event.key === 'ArrowRight' || event.key === 'PageDown' || event.key === ' ') ctx.next?.();
    });

    const surface = document.getElementById('reader-surface');

    surface?.addEventListener('click', (event) => {
        const { left, width } = surface.getBoundingClientRect();
        const position = (event.clientX - left) / width;

        // Edges page; the middle is left alone. Toggling the chrome is
        // handled once in setupChromeReveal(), which every format reaches —
        // doing it here too would fire twice and cancel itself out.
        if (position < 0.33) ctx.prev?.();
        else if (position > 0.67) ctx.next?.();
    });
}

function setupChrome(ctx) {
    const panel = document.getElementById('reader-settings');

    const toggle = document.getElementById('reader-settings-toggle');

    toggle?.addEventListener('click', (event) => {
        event.stopPropagation();

        const open = panel?.classList.toggle('hidden') === false;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        // Keep the chrome up while the sheet is open, or the bar it was
        // opened from fades out from under it.
        if (open) showChrome();
    });

    document.getElementById('reader-settings-close')?.addEventListener('click', () => {
        panel?.classList.add('hidden');
        toggle?.setAttribute('aria-expanded', 'false');
    });

    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.addEventListener('click', () => {
            ctx.prefs.theme = button.dataset.themeOption;
            applyTheme(ctx.prefs.theme);
            savePrefs(ctx.prefs);
            ctx.onPrefsChange?.();
            markActive('[data-theme-option]', 'themeOption', ctx.prefs.theme);
        });
    });

    document.getElementById('reader-font-smaller')?.addEventListener('click', () => {
        ctx.prefs.fontSize = stepValue(FONT_SIZES, ctx.prefs.fontSize, -1);
        ctx.prefs.zoom = clamp(ctx.prefs.zoom - 0.1, 0.6, 2);
        savePrefs(ctx.prefs);
        ctx.onPrefsChange?.();
    });

    document.getElementById('reader-font-larger')?.addEventListener('click', () => {
        ctx.prefs.fontSize = stepValue(FONT_SIZES, ctx.prefs.fontSize, 1);
        ctx.prefs.zoom = clamp(ctx.prefs.zoom + 0.1, 0.6, 2);
        savePrefs(ctx.prefs);
        ctx.onPrefsChange?.();
    });

    document.getElementById('reader-serif')?.addEventListener('change', (event) => {
        ctx.prefs.serif = event.target.checked;
        savePrefs(ctx.prefs);
        ctx.onPrefsChange?.();
    });

    // Light controls update live while dragging, but only persist on release —
    // writing localStorage on every input event is wasted work.
    bindSlider('reader-brightness', ctx, 'brightness');
    bindSlider('reader-warmth', ctx, 'warmth');

    applyLight(ctx.prefs);

    markActive('[data-theme-option]', 'themeOption', ctx.prefs.theme);

    const serifToggle = document.getElementById('reader-serif');
    if (serifToggle) serifToggle.checked = ctx.prefs.serif;

    // Clicking outside the sheet closes it.
    document.addEventListener('click', (event) => {
        if (!panel || panel.classList.contains('hidden')) return;
        if (panel.contains(event.target) || toggle?.contains(event.target)) return;

        panel.classList.add('hidden');
        toggle?.setAttribute('aria-expanded', 'false');
    });

    // Escape closes whatever is open, then the chrome itself.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        if (panel && !panel.classList.contains('hidden')) {
            panel.classList.add('hidden');
            toggle?.setAttribute('aria-expanded', 'false');

            return;
        }

        hideChrome();
    });
}

/**
 * Wires a range input to a preference, updating the light live and saving
 * only once the drag ends.
 */
function bindSlider(id, ctx, key) {
    const slider = document.getElementById(id);

    if (!slider) return;

    slider.value = String(ctx.prefs[key]);

    slider.addEventListener('input', (event) => {
        ctx.prefs[key] = Number(event.target.value);
        applyLight(ctx.prefs);
    });

    slider.addEventListener('change', () => savePrefs(ctx.prefs));
}

/**
 * Makes the controls reachable from any format.
 *
 * This lives here rather than in bindPaging() because PDFs supply their own
 * paging and never call it — which left a PDF with no way to reveal the
 * chrome at all, and so no way to change a setting or leave the page.
 */
function setupChromeReveal() {
    const surface = document.getElementById('reader-page');

    if (!surface) return;

    surface.addEventListener('click', (event) => {
        // Clicks on the bars, a panel, or a highlight are not a request to
        // toggle the chrome.
        if (event.target.closest('.reader-chrome, .reader-sheet, .reader-notes, .reader-popover, .reader-note-editor, .reader-highlight')) {
            return;
        }

        // Selecting text shouldn't dismiss the controls.
        if (window.getSelection()?.toString()) return;

        toggleChrome();
    });

    // Moving the pointer brings them back on a desktop, the way a video
    // player does — no hunting for an invisible tap target.
    surface.addEventListener('mousemove', debounce(() => {
        if (document.getElementById('reader-chrome')?.classList.contains('is-hidden')) {
            showChrome();
        }
    }, 200));

    // Visible on arrival and held longer than a normal reveal, so the
    // controls are seen before they go away.
    showChrome({ linger: 6000 });
}

/**
 * Chrome shows on a tap and fades on its own.
 *
 * An e-reader should be almost entirely page — controls that sit permanently
 * over the text are in the way of the only thing on screen that matters.
 */
let chromeTimer;

function toggleChrome() {
    const chrome = document.getElementById('reader-chrome');

    if (!chrome) return;

    chrome.classList.contains('is-hidden') ? showChrome() : hideChrome();
}

function showChrome({ linger = 3500 } = {}) {
    const chrome = document.getElementById('reader-chrome');

    if (!chrome) return;

    chrome.classList.remove('is-hidden');
    clearTimeout(chromeTimer);

    chromeTimer = setTimeout(() => {
        // Never fade out from under an open panel — someone is using it.
        if (document.querySelector('.reader-sheet:not(.hidden), .reader-notes:not(.hidden), .reader-note-editor:not(.hidden)')) {
            showChrome();

            return;
        }

        hideChrome();
    }, linger);
}

function hideChrome() {
    clearTimeout(chromeTimer);
    document.getElementById('reader-chrome')?.classList.add('is-hidden');
}

/**
 * Themes drive CSS custom properties so the page, the chrome, and the
 * surrounding app all shift together.
 */
function applyTheme(name) {
    const theme = THEMES[name] ?? THEMES.night;
    const root = document.getElementById('reader-root') ?? document.body;

    root.style.setProperty('--reader-page', theme.page);
    root.style.setProperty('--reader-ink', theme.ink);
    root.style.setProperty('--reader-muted', theme.muted);
    root.style.setProperty('--reader-chrome', theme.chrome);
    root.style.setProperty('--reader-surround', theme.surround);
}

/**
 * The front light: brightness and warmth, the way a Paperwhite works.
 *
 * Both are done with an overlay rather than a CSS filter on the page. A filter
 * would force the browser to re-composite the text layer on every change,
 * which visibly stutters while dragging a slider; an overlay is a single
 * composited layer that costs nothing to update.
 *
 * The overlay sits above the page but below the chrome, and never intercepts
 * clicks — paging by tapping the page edges has to keep working through it.
 */
function applyLight(prefs) {
    const dim = document.getElementById('reader-dim');
    const warm = document.getElementById('reader-warm');

    if (dim) {
        // Brightness runs 20–100%; the overlay is the inverse, so full
        // brightness means no overlay at all.
        dim.style.opacity = String((100 - prefs.brightness) / 100);
    }

    if (warm) {
        // Amber wash, strongest at warmth 100. Capped at 0.35 because past
        // that the ink starts to lose contrast against the page.
        warm.style.opacity = String((prefs.warmth / 100) * 0.35);
    }
}

function markActive(selector, dataKey, value) {
    document.querySelectorAll(selector).forEach((button) => {
        button.classList.toggle('ring-2', button.dataset[dataKey] === value);
    });
}

/* ---------------------------------------------------------------- prefs */

function loadPrefs() {
    const defaults = {
        theme: 'night',
        fontSize: 110,
        lineHeight: 1.6,
        serif: true,
        zoom: 1,
        // Fit width by default: it's what reads best on a phone, and it's the
        // mode that makes differently-shaped PDFs look consistent.
        pdfFit: 'width',
        pdfZoom: 1,
        // Reflowed reading is a lasting preference, not a per-session mode.
        textMode: false,
        // Full brightness, no warmth — the page as the theme intends it.
        brightness: 100,
        warmth: 0,
    };

    try {
        return { ...defaults, ...JSON.parse(localStorage.getItem(PREFS_KEY) ?? '{}') };
    } catch {
        return defaults;
    }
}

function savePrefs(prefs) {
    try {
        localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
    } catch {
        // Private browsing blocks storage; preferences just won't persist.
    }
}

/* ---------------------------------------------------------------- utils */

function stepValue(list, current, direction) {
    const index = list.indexOf(current);
    const next = (index === -1 ? list.indexOf(110) : index) + direction;

    return list[clamp(next, 0, list.length - 1)];
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function debounce(fn, wait) {
    let timer;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}
