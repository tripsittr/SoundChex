// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Contents panel: chapters, search, and the illustration gallery.
 *
 * All three answer the same question — "take me to a particular place in this
 * book" — so they share one panel with tabs rather than three separate
 * controls competing for space in the header.
 */

export function setupContents(ctx, config) {
    const panel = document.getElementById('reader-contents');
    const toggle = document.getElementById('reader-contents-toggle');

    if (!panel || !toggle || !config.contentsUrl) return;

    const chapterList = document.getElementById('reader-chapter-list');
    const imageGrid = document.getElementById('reader-image-grid');
    const searchInput = document.getElementById('reader-search-input');
    const searchResults = document.getElementById('reader-search-results');
    const searchStatus = document.getElementById('reader-search-status');

    let loaded = false;

    const close = () => {
        panel.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    };

    const open = async () => {
        panel.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');

        if (!loaded) {
            loaded = true;
            await load();
        }
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();

        panel.classList.contains('hidden') ? open() : close();
    });

    document.getElementById('reader-contents-close')?.addEventListener('click', close);

    /* -------------------------------------------------------------- tabs */

    panel.querySelectorAll('[data-contents-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            const name = tab.dataset.contentsTab;

            panel.querySelectorAll('[data-contents-tab]').forEach((other) => {
                other.classList.toggle('is-active', other === tab);
            });

            panel.querySelectorAll('[data-contents-panel]').forEach((section) => {
                section.classList.toggle('hidden', section.dataset.contentsPanel !== name);
            });

            if (name === 'search') searchInput?.focus();
        });
    });

    /* ---------------------------------------------------------- chapters */

    const jump = (page) => {
        ctx.goToPage?.(page);
        close();
    };

    async function load() {
        let data;

        try {
            const response = await fetch(config.contentsUrl, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return;

            data = await response.json();
        } catch {
            return;
        }

        renderChapters(data.chapters ?? []);
        renderImages(data.images ?? []);
    }

    function renderChapters(chapters) {
        const empty = document.getElementById('reader-chapters-empty');

        if (!chapterList) return;

        chapterList.replaceChildren();

        if (chapters.length === 0) {
            empty?.classList.remove('hidden');

            return;
        }

        empty?.classList.add('hidden');

        chapters.forEach((chapter) => {
            const entry = document.createElement('li');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'reader-toc-item';
            // Nesting is shown by indent rather than a nested list: the tree
            // is only ever read top to bottom.
            button.style.paddingLeft = `${0.75 + (chapter.depth * 0.85)}rem`;

            const title = document.createElement('span');
            title.className = 'reader-toc-title';
            title.textContent = chapter.title;

            const page = document.createElement('span');
            page.className = 'reader-toc-page';
            page.textContent = String(chapter.page);

            button.append(title, page);
            button.addEventListener('click', () => jump(chapter.page));

            entry.append(button);
            chapterList.append(entry);
        });
    }

    function renderImages(images) {
        const empty = document.getElementById('reader-images-empty');

        if (!imageGrid) return;

        imageGrid.replaceChildren();

        if (images.length === 0) {
            empty?.classList.remove('hidden');

            return;
        }

        empty?.classList.add('hidden');

        images.forEach((image) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'reader-image-tile';

            const img = document.createElement('img');
            img.src = image.url;
            img.alt = `Illustration on page ${image.page}`;
            img.loading = 'lazy';

            const caption = document.createElement('span');
            caption.className = 'reader-image-page';
            caption.textContent = `p${image.page}`;

            button.append(img, caption);

            // Tapping opens the page it came from, which is what someone
            // browsing a gallery of a book's plates actually wants.
            button.addEventListener('click', () => jump(image.page));

            imageGrid.append(button);
        });
    }

    /* ------------------------------------------------------------ search */

    let searchTimer;

    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);

        const query = searchInput.value.trim();

        if (query.length < 2) {
            searchResults.replaceChildren();
            searchStatus.textContent = '';

            return;
        }

        searchStatus.textContent = 'Searching…';

        // Debounced: a full-text scan on every keystroke would fire a request
        // per letter.
        searchTimer = setTimeout(() => runSearch(query), 250);
    });

    async function runSearch(query) {
        let data;

        try {
            const response = await fetch(
                `${config.searchUrl}?q=${encodeURIComponent(query)}`,
                { headers: { Accept: 'application/json' } },
            );

            data = await response.json();
        } catch {
            searchStatus.textContent = 'Search failed.';

            return;
        }

        const results = data.results ?? [];

        searchResults.replaceChildren();
        searchStatus.textContent = results.length
            ? `${results.length} ${results.length === 1 ? 'result' : 'results'}`
            : 'No matches';

        results.forEach((result) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'reader-search-result';

            const page = document.createElement('span');
            page.className = 'reader-search-page';
            page.textContent = `p${result.page}`;

            const snippet = document.createElement('span');
            snippet.className = 'reader-search-snippet';
            // The server marks matches with control characters rather than
            // HTML, so the text is set safely and the marks become elements
            // here — a book containing angle brackets can't inject anything.
            highlightInto(snippet, result.snippet);

            button.append(page, snippet);

            if (result.source === 'ocr') {
                const badge = document.createElement('span');
                badge.className = 'reader-search-badge';
                badge.textContent = 'scanned';
                badge.title = 'Recognised from a scanned page — may contain mistakes.';
                button.append(badge);
            }

            button.addEventListener('click', () => jump(result.page));

            searchResults.append(button);
        });
    }

    /** Turns \x02…\x03 sentinels into <mark> without parsing any HTML. */
    function highlightInto(element, text) {
        text.split('\x02').forEach((chunk, index) => {
            if (index === 0) {
                element.append(document.createTextNode(chunk));

                return;
            }

            const [marked, ...rest] = chunk.split('\x03');

            const mark = document.createElement('mark');
            mark.className = 'reader-search-mark';
            mark.textContent = marked;

            element.append(mark, document.createTextNode(rest.join('\x03')));
        });
    }

    // Clicking away closes it, matching the settings sheet.
    document.addEventListener('click', (event) => {
        if (panel.classList.contains('hidden')) return;
        if (panel.contains(event.target) || toggle.contains(event.target)) return;

        close();
    });
}
