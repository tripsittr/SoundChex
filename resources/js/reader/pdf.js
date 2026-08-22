import { HIGHLIGHT_COLORS } from './annotations.js';

/**
 * PDF reading surface.
 *
 * Uploaded PDFs are all different shapes — A4 scans, US Letter, cropped
 * trade paperbacks, 2-up spreads. Rather than showing each at whatever size
 * it happens to be, every document is normalised to a fit mode the reader
 * chooses once, and that choice is remembered across books.
 *
 * A real text layer is rendered over the canvas. It is what makes selection,
 * highlighting and notes possible — a canvas alone is just pixels, and the
 * browser has nothing to select.
 */

/** Fit modes. Width is the default: it's what a phone wants. */
export const FIT_MODES = {
    width: 'Fit width',
    page: 'Whole page',
    actual: 'Actual size',
};

export async function mountPdf(config, ctx) {
    const surface = document.getElementById('reader-surface');

    let pdfjs;
    let doc;

    try {
        const [lib, { default: pdfWorker }] = await Promise.all([
            import('pdfjs-dist'),
            import('pdfjs-dist/build/pdf.worker.mjs?url'),
        ]);

        pdfjs = lib;
        pdfjs.GlobalWorkerOptions.workerSrc = pdfWorker;

        doc = await pdfjs.getDocument({ url: config.fileUrl }).promise;
    } catch {
        if (ctx.status) ctx.status.textContent = 'Could not open this PDF.';

        return;
    }

    const total = doc.numPages;
    let page = clamp(parseInt(config.startLocation ?? '1', 10) || 1, 1, total);

    // The scroller is what makes panning work: at high zoom the canvas is
    // larger than the viewport and this is what moves underneath it.
    const scroller = document.createElement('div');
    scroller.className = 'reader-pdf-scroller';

    const stage = document.createElement('div');
    stage.className = 'reader-pdf-stage';

    const canvas = document.createElement('canvas');
    canvas.className = 'reader-pdf-canvas';

    // Selectable text, positioned to match the rendered glyphs exactly.
    const textLayer = document.createElement('div');
    textLayer.className = 'reader-pdf-textlayer';

    // Highlights paint under the text layer so selection still works on top.
    const highlightLayer = document.createElement('div');
    highlightLayer.className = 'reader-pdf-highlights';

    stage.append(canvas, highlightLayer, textLayer);
    scroller.append(stage);
    surface.replaceChildren(scroller);
    ctx.status?.remove();

    // Rendering is async and a fast pager can outrun it; only the newest
    // render is allowed to paint.
    let renderToken = 0;
    let renderTask = null;
    let currentViewport = null;

    const render = async () => {
        const token = ++renderToken;

        const pdfPage = await doc.getPage(page);

        if (token !== renderToken) return;

        const base = pdfPage.getViewport({ scale: 1 });
        const scale = fitScale(base, scroller, ctx.prefs);
        const ratio = window.devicePixelRatio || 1;
        const viewport = pdfPage.getViewport({ scale });

        currentViewport = viewport;

        // The canvas is drawn at device resolution and displayed at CSS
        // resolution — without that, text is soft on a retina screen.
        canvas.width = Math.floor(viewport.width * ratio);
        canvas.height = Math.floor(viewport.height * ratio);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;

        stage.style.width = `${Math.floor(viewport.width)}px`;
        stage.style.height = `${Math.floor(viewport.height)}px`;

        // Cancelling in-flight work avoids "canvas in use" errors when pages
        // are turned quickly.
        renderTask?.cancel();

        renderTask = pdfPage.render({
            canvasContext: canvas.getContext('2d'),
            viewport: pdfPage.getViewport({ scale: scale * ratio }),
        });

        try {
            await renderTask.promise;
        } catch {
            return; // Cancelled by a newer render.
        }

        if (token !== renderToken) return;

        const glyphCount = await paintTextLayer(pdfjs, pdfPage, viewport, textLayer);

        if (token !== renderToken) return;

        // No embedded text means this page is a scan. Recognised words, if we
        // have them, become the selectable layer instead — which is what lets
        // a scanned book be highlighted like any other.
        if (glyphCount === 0) {
            paintOcrLayer(ctx, textLayer, viewport, page, token, () => token === renderToken);
        }

        drawHighlights(ctx, highlightLayer, page, viewport);

        ctx.saveProgress(String(page), Math.round((page / total) * 100));
        ctx.onPageRendered?.(page, total);
    };

    const step = (delta) => {
        const next = clamp(page + delta, 1, total);

        if (next === page) return;

        page = next;
        // A new page always starts at the top; keeping the old scroll offset
        // drops the reader into the middle of it.
        scroller.scrollTop = 0;
        render();
    };

    ctx.prev = () => step(-1);
    ctx.next = () => step(1);
    ctx.goToPage = (target) => {
        const next = clamp(target, 1, total);

        if (next === page) return;

        page = next;
        scroller.scrollTop = 0;
        render();
    };
    ctx.currentPage = () => page;
    ctx.pageCount = () => total;
    ctx.onPrefsChange = render;
    ctx.redrawHighlights = () => currentViewport
        && drawHighlights(ctx, highlightLayer, page, currentViewport);

    // Selection → highlight. Handled on the stage so a selection that ends
    // outside the text layer still registers.
    ctx.captureSelection = () => captureSelection(textLayer, currentViewport, page);

    enablePanning(scroller, stage, ctx);
    bindPaging(ctx, scroller);

    window.addEventListener('resize', debounce(render, 200));

    await render();
}

/**
 * Scale that satisfies the chosen fit mode.
 *
 * This is the normalisation: whatever the document's own page size is, the
 * reader sees a consistent result. Zoom multiplies on top, so "fit width at
 * 150%" behaves the way a reader expects.
 */
function fitScale(base, scroller, prefs) {
    // Padding is subtracted so a fitted page doesn't touch the edges.
    const available = {
        width: Math.max(scroller.clientWidth - 32, 120),
        height: Math.max(scroller.clientHeight - 32, 120),
    };

    const zoom = prefs.pdfZoom ?? 1;

    const fit = {
        width: available.width / base.width,
        page: Math.min(available.width / base.width, available.height / base.height),
        // "Actual size" means CSS pixels per PDF point, which is what a
        // 100% zoom means in every other PDF viewer.
        actual: 1,
    }[prefs.pdfFit ?? 'width'] ?? available.width / base.width;

    return fit * zoom;
}

/**
 * Renders pdf.js's text layer over the canvas.
 *
 * The spans are transparent — they exist purely so the browser has real text
 * to select. Their position has to track the viewport exactly or selection
 * highlights land in the wrong place.
 */
async function paintTextLayer(pdfjs, pdfPage, viewport, container) {
    container.replaceChildren();
    container.style.width = `${Math.floor(viewport.width)}px`;
    container.style.height = `${Math.floor(viewport.height)}px`;
    container.style.setProperty('--scale-factor', String(viewport.scale));

    try {
        const textContent = await pdfPage.getTextContent();

        // A page with no items is a scan. Reported so the caller can fall
        // back to recognised text rather than leaving it unselectable.
        const glyphCount = textContent.items
            .reduce((total, item) => total + (item.str?.trim().length ?? 0), 0);

        if (glyphCount === 0) return 0;

        // pdf.js ≥ 4 exposes TextLayer; older builds export renderTextLayer.
        if (typeof pdfjs.TextLayer === 'function') {
            const layer = new pdfjs.TextLayer({
                textContentSource: textContent,
                container,
                viewport,
            });

            await layer.render();

            return glyphCount;
        }

        if (typeof pdfjs.renderTextLayer === 'function') {
            await pdfjs.renderTextLayer({
                textContentSource: textContent,
                container,
                viewport,
            }).promise;
        }

        return glyphCount;
    } catch {
        // Treated as a scan: the OCR fallback is strictly better than an
        // unselectable page.
        return 0;
    }
}

/**
 * Builds a selectable layer from recognised words.
 *
 * Each word becomes an absolutely-positioned span sized to its recognised box,
 * with the font scaled to fill that box. The text is transparent, exactly like
 * pdf.js's own layer — the browser selects it, and what the reader sees is the
 * scanned image underneath.
 *
 * Recognition is asynchronous and may need queuing server-side, so this polls
 * briefly rather than blocking the page render behind it.
 */
async function paintOcrLayer(ctx, container, viewport, page, token, stillCurrent) {
    if (!ctx.ocrUrl) return;

    const url = ctx.ocrUrl.replace('__PAGE__', String(page));

    // Recognition takes a second or two; a handful of polls covers it without
    // hammering the server if it never arrives.
    for (let attempt = 0; attempt < 12; attempt++) {
        if (!stillCurrent()) return;

        let data;

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok) return;

            data = await response.json();
        } catch {
            return;
        }

        if (data.status === 'complete') {
            if (!stillCurrent()) return;

            renderOcrWords(data.words ?? [], container, viewport);
            ctx.onOcrReady?.(page, data);

            return;
        }

        // Settled answers — no amount of waiting changes them, so stop polling.
        // 'blank' belongs here: the page was read and had nothing on it.
        if (['blank', 'skipped', 'failed', 'unavailable', 'disabled'].includes(data.status)) {
            ctx.onOcrUnavailable?.(page, data);

            return;
        }

        // 'queued' — wait for the worker, backing off as it goes.
        await new Promise((resolve) => setTimeout(resolve, 500 + (attempt * 250)));
    }
}

function renderOcrWords(words, container, viewport) {
    container.replaceChildren();

    const fragment = document.createDocumentFragment();

    words.forEach((word) => {
        const span = document.createElement('span');

        span.textContent = word.t;
        span.style.left = `${word.x * viewport.width}px`;
        span.style.top = `${word.y * viewport.height}px`;
        span.style.width = `${word.w * viewport.width}px`;
        span.style.height = `${word.h * viewport.height}px`;
        // Sized to the recognised box so the selection rectangle matches the
        // ink underneath rather than a default line height.
        span.style.fontSize = `${word.h * viewport.height}px`;
        span.style.lineHeight = '1';
        // Squeezes the glyphs to exactly the recognised width, so a long word
        // doesn't overhang the next one and break selection order.
        span.style.transform = 'scaleX(1)';
        span.style.transformOrigin = '0 0';
        span.dataset.ocr = 'true';

        fragment.append(span);
    });

    container.append(fragment);

    // Widths are measured after layout; scaling each span to its box keeps the
    // transparent text aligned with the image at any zoom.
    container.querySelectorAll('[data-ocr]').forEach((span) => {
        const target = parseFloat(span.style.width);
        const actual = span.getBoundingClientRect().width;

        if (actual > 0 && target > 0) {
            span.style.transform = `scaleX(${target / actual})`;
        }
    });
}

/**
 * Turns the current selection into a storable location.
 *
 * Rectangles are normalised to 0..1 of the page box, so a highlight made at
 * one zoom level lands correctly at any other — and on a phone after being
 * made on a desktop.
 */
function captureSelection(textLayer, viewport, page) {
    const selection = window.getSelection();

    if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
        return null;
    }

    const range = selection.getRangeAt(0);

    // Ignore selections that aren't inside the page text.
    if (!textLayer.contains(range.commonAncestorContainer)) {
        return null;
    }

    const text = selection.toString().trim();

    if (!text) return null;

    const stageRect = textLayer.getBoundingClientRect();

    // getClientRects returns a rectangle per client rect, and for text spanning
    // several spans on one line those overlap heavily. Painting each one
    // separately stacks translucent layers and reads as a darker highlight, so
    // near-duplicates are merged here rather than compensated for in CSS.
    const raw = Array.from(range.getClientRects())
        .filter((rect) => rect.width > 0 && rect.height > 0);

    const merged = [];

    raw.forEach((rect) => {
        const existing = merged.find((other) => overlaps(other, rect));

        if (!existing) {
            merged.push({
                left: rect.left,
                top: rect.top,
                right: rect.right,
                bottom: rect.bottom,
            });

            return;
        }

        // Same line — widen the one already there instead of adding another.
        existing.left = Math.min(existing.left, rect.left);
        existing.top = Math.min(existing.top, rect.top);
        existing.right = Math.max(existing.right, rect.right);
        existing.bottom = Math.max(existing.bottom, rect.bottom);
    });

    const rects = merged.map((rect) => ({
        x: (rect.left - stageRect.left) / stageRect.width,
        y: (rect.top - stageRect.top) / stageRect.height,
        w: (rect.right - rect.left) / stageRect.width,
        h: (rect.bottom - rect.top) / stageRect.height,
    }));

    if (rects.length === 0) return null;

    return {
        page,
        excerpt: text.slice(0, 2000),
        location: { page, rects },
        // Where to anchor the popover, in viewport coordinates.
        anchor: range.getBoundingClientRect(),
    };
}

/**
 * Whether two rectangles sit on the same line of text.
 *
 * Vertical overlap is what decides it: rects on one line share most of their
 * height, while rects on consecutive lines barely touch.
 */
function overlaps(a, b) {
    const top = Math.max(a.top, b.top);
    const bottom = Math.min(a.bottom, b.bottom);
    const shared = bottom - top;

    if (shared <= 0) return false;

    const shorter = Math.min(a.bottom - a.top, b.bottom - b.top);

    return shorter > 0 && shared / shorter > 0.5;
}

/**
 * Paints stored highlights for the visible page.
 *
 * Coordinates are fractions of the page, so this multiplies back up by the
 * current viewport — which is what makes them survive zoom and rotation.
 */
function drawHighlights(ctx, layer, page, viewport) {
    layer.replaceChildren();
    layer.style.width = `${Math.floor(viewport.width)}px`;
    layer.style.height = `${Math.floor(viewport.height)}px`;

    const annotations = ctx.annotations?.forPage(page) ?? [];

    annotations.forEach((annotation) => {
        const rects = annotation.location?.rects ?? [];
        const color = HIGHLIGHT_COLORS[annotation.color] ?? HIGHLIGHT_COLORS.yellow;

        rects.forEach((rect) => {
            const mark = document.createElement('button');
            mark.type = 'button';
            mark.className = 'reader-highlight';
            mark.dataset.annotationId = String(annotation.id);
            mark.style.left = `${rect.x * 100}%`;
            mark.style.top = `${rect.y * 100}%`;
            mark.style.width = `${rect.w * 100}%`;
            mark.style.height = `${rect.h * 100}%`;
            mark.style.background = color.ink;
            mark.setAttribute('aria-label', annotation.note ? 'Note' : 'Highlight');

            if (annotation.note) mark.dataset.hasNote = 'true';

            mark.addEventListener('click', (event) => {
                event.stopPropagation();
                ctx.onHighlightClick?.(annotation, mark);
            });

            layer.append(mark);
        });
    });
}

/**
 * Drag-to-pan, for when a page is zoomed past the viewport.
 *
 * Only engages when there's actually overflow — otherwise it would swallow
 * the taps that turn pages.
 */
function enablePanning(scroller, stage, ctx) {
    let panning = false;
    let start = { x: 0, y: 0, left: 0, top: 0 };

    const overflows = () => scroller.scrollWidth > scroller.clientWidth + 1
        || scroller.scrollHeight > scroller.clientHeight + 1;

    scroller.addEventListener('pointerdown', (event) => {
        // Never start a pan from a text selection or a highlight.
        if (event.target.closest('.reader-highlight')) return;
        if (!overflows() || event.button !== 0) return;
        if (window.getSelection()?.toString()) return;

        panning = true;
        start = {
            x: event.clientX,
            y: event.clientY,
            left: scroller.scrollLeft,
            top: scroller.scrollTop,
        };
    });

    scroller.addEventListener('pointermove', (event) => {
        if (!panning) return;

        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;

        // Below the threshold this is a click or the start of a selection,
        // not a drag.
        if (Math.abs(dx) < 4 && Math.abs(dy) < 4) return;

        scroller.scrollLeft = start.left - dx;
        scroller.scrollTop = start.top - dy;
        scroller.classList.add('is-panning');
        event.preventDefault();
    });

    const endPan = () => {
        panning = false;
        scroller.classList.remove('is-panning');
    };

    scroller.addEventListener('pointerup', endPan);
    scroller.addEventListener('pointercancel', endPan);
    scroller.addEventListener('pointerleave', endPan);

    // Ctrl/⌘ + wheel zooms, matching every other document viewer.
    scroller.addEventListener('wheel', (event) => {
        if (!event.ctrlKey && !event.metaKey) return;

        event.preventDefault();
        ctx.adjustZoom?.(event.deltaY < 0 ? 0.1 : -0.1);
    }, { passive: false });
}

/**
 * Keyboard paging, and edge taps that don't fight text selection.
 */
function bindPaging(ctx, scroller) {
    document.addEventListener('keydown', (event) => {
        if (event.target.matches('input, textarea')) return;

        if (event.key === 'ArrowRight' || event.key === 'PageDown') ctx.next?.();
        if (event.key === 'ArrowLeft' || event.key === 'PageUp') ctx.prev?.();
    });

    // A vertical scroll past the bottom of a zoomed page turns to the next
    // one, which is what makes long documents readable on a phone.
    scroller.addEventListener('scroll', debounce(() => {
        ctx.onScroll?.(scroller);
    }, 120));
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function debounce(fn, wait) {
    let timer;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}
