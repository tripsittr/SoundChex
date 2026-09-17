// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Reflowed reading view for PDFs.
 *
 * A PDF is fixed-position glyphs, so this is never the accurate view — layout,
 * tables and multi-column pages are lost. What it buys is readability: a page
 * of a typical trade paperback holds barely a hundred words, which on a phone
 * means a page turn every few seconds and a column of text stranded in a tall
 * empty viewport.
 *
 * So this stitches a run of pages into one continuous column and keeps loading
 * as you scroll, with the book's own illustrations placed inline where they
 * belong.
 */

/** Pages fetched per request. About 1,300 words — a few screens of reading. */
const PAGE_RUN = 12;

export function createTextMode(config, ctx) {
    const container = document.getElementById('reader-textmode-content');
    const scroller = document.getElementById('reader-textmode');

    if (!container || !scroller || !config.readingTextUrl) return null;

    let nextPage = 1;
    let hasMore = true;
    let loading = false;
    let sawOcr = false;

    const reset = (from) => {
        container.replaceChildren();
        nextPage = Math.max(1, from);
        hasMore = true;
        sawOcr = false;
        scroller.scrollTop = 0;
    };

    /**
     * Appends the next run of pages.
     */
    const loadMore = async () => {
        if (loading || !hasMore) return;

        loading = true;

        let data;

        try {
            const response = await fetch(
                `${config.readingTextUrl}?from=${nextPage}&pages=${PAGE_RUN}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) throw new Error('failed');

            data = await response.json();
        } catch {
            loading = false;

            return;
        }

        hasMore = Boolean(data.hasMore);
        nextPage = (data.to ?? nextPage) + 1;

        if ((data.pages ?? []).length === 0) {
            // Nothing here, but there may be text further on — a run of
            // plates, say. Keep going rather than stopping at the first gap.
            loading = false;

            if (hasMore) await loadMore();

            return;
        }

        data.pages.forEach((page) => appendPage(page));

        loading = false;

        // A short run may not fill the viewport, which would leave nothing to
        // scroll and no way to trigger the next load.
        if (hasMore && scroller.scrollHeight <= scroller.clientHeight + 40) {
            await loadMore();
        }
    };

    const appendPage = (page) => {
        if (page.ocr) sawOcr = true;

        // A marker per page, so the position indicator and chapter jumps
        // still work in a continuous column.
        const anchor = document.createElement('div');
        anchor.className = 'reader-flow-anchor';
        anchor.dataset.page = String(page.page);
        container.append(anchor);

        // Images belonging to this page, before its text: a plate is nearly
        // always the top of the page it sits on.
        page.images.forEach((image) => {
            const figure = document.createElement('figure');
            figure.className = 'reader-flow-figure';

            const img = document.createElement('img');
            img.src = image.url;
            img.alt = '';
            img.loading = 'lazy';

            figure.append(img);
            container.append(figure);
        });

        linesToParagraphs(splitLines(page.text)).forEach((text) => {
            const paragraph = document.createElement('p');
            paragraph.textContent = text;
            container.append(paragraph);
        });
    };

    // Infinite scroll, with a generous margin so the next run is already in
    // place before the reader reaches the end of this one.
    scroller.addEventListener('scroll', () => {
        const remaining = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight;

        if (remaining < scroller.clientHeight * 1.5) loadMore();

        reportPosition();
    });

    /**
     * Tells the rest of the reader which page is on screen, so progress and
     * the page number keep working while scrolling a continuous column.
     */
    const reportPosition = () => {
        const anchors = container.querySelectorAll('.reader-flow-anchor');
        const top = scroller.scrollTop;

        let current = null;

        anchors.forEach((anchor) => {
            if (anchor.offsetTop - scroller.offsetTop <= top + 80) {
                current = Number(anchor.dataset.page);
            }
        });

        if (current !== null) ctx.onFlowPage?.(current);
    };

    /**
     * Finds a page's anchor in what's already loaded.
     */
    const anchorFor = (page) => container
        .querySelector(`.reader-flow-anchor[data-page="${page}"]`);

    return {
        /** Starts (or restarts) the flow at a given page. */
        async show(from = 1) {
            reset(from);
            await loadMore();
            reportPosition();
        },

        /**
         * Jumps to a page.
         *
         * Scrolls to it when it's already loaded — going backwards is the
         * common case and rebuilding the whole column to reach a page that's
         * sitting above you would lose everything read so far. Anything
         * outside the loaded range restarts the flow there instead.
         */
        async goTo(page) {
            const existing = anchorFor(page);

            if (existing) {
                // Offset by the top padding so the first line isn't tucked
                // under the header bar.
                scroller.scrollTo({
                    top: Math.max(0, existing.offsetTop - scroller.offsetTop - 24),
                    behavior: 'smooth',
                });

                reportPosition();

                return;
            }

            // Ahead of what's loaded but close: keep reading forward rather
            // than throwing the column away.
            if (page > nextPage - 1 && page < nextPage + PAGE_RUN * 2) {
                while (hasMore && !anchorFor(page)) {
                    // eslint-disable-next-line no-await-in-loop
                    await loadMore();
                }

                const loaded = anchorFor(page);

                if (loaded) {
                    scroller.scrollTo({
                        top: Math.max(0, loaded.offsetTop - scroller.offsetTop - 24),
                        behavior: 'smooth',
                    });

                    reportPosition();

                    return;
                }
            }

            await this.show(page);
        },

        /** Whether any of the loaded text came from recognition. */
        usedOcr: () => sawOcr,
    };
}

/** Splits stored page text into trimmed, non-empty lines. */
function splitLines(text) {
    return String(text ?? '')
        .split(/\r?\n/)
        .map((line) => ({ text: line.trim() }))
        .filter((line) => line.text !== '');
}

/**
 * Joins extracted lines into paragraphs.
 *
 * A line that ends mid-sentence is a wrapped line, not a new paragraph. This
 * is a heuristic and it is wrong sometimes — which is why the page view stays
 * one tap away.
 */
export function linesToParagraphs(lines) {
    const paragraphs = [];

    // Accumulated as a string rather than an array of lines: a word split
    // across a line break has to close up with no space at all, which a
    // join(' ') at the end would put back.
    let buffer = '';

    // Set when the previous line ended mid-word, so the next one joins flush.
    let hyphenated = false;

    const flush = () => {
        if (buffer.trim() === '') return;

        paragraphs.push(buffer.replace(/\s+/g, ' ').trim());
        buffer = '';
    };

    lines.forEach((line, index) => {
        const text = line.text;

        if (buffer === '') {
            buffer = text;
        } else {
            buffer += (hyphenated ? '' : ' ') + text;
        }

        // A trailing hyphen at a line break is a split word ("port-" + "hole").
        // Drop the hyphen and join the next line directly onto this one.
        hyphenated = text.endsWith('-') && index < lines.length - 1;

        if (hyphenated) {
            buffer = buffer.slice(0, -1);

            return;
        }

        // Sentence-final punctuation on a noticeably short line is how the
        // last line of a paragraph looks. Both are needed: a short line
        // mid-sentence is just a wrap, and a full line ending in a period is
        // usually a sentence break inside a paragraph.
        if (/[.!?"']\s*$/.test(text) && text.length < 45) flush();
    });

    flush();

    return paragraphs;
}
