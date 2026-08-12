import { HIGHLIGHT_COLORS } from './annotations.js';

/**
 * The interface around highlights: the popover that appears on selection, the
 * note editor, and the sidebar listing everything in the book.
 *
 * Kept apart from the PDF renderer because none of it is PDF-specific — an
 * EPUB highlight would use the same popover and the same sidebar.
 */
export function setupAnnotationUi(ctx) {
    const popover = document.getElementById('reader-selection-popover');
    const noteEditor = document.getElementById('reader-note-editor');
    const noteInput = document.getElementById('reader-note-input');
    const sidebar = document.getElementById('reader-notes');
    const list = document.getElementById('reader-notes-list');
    const emptyState = document.getElementById('reader-notes-empty');
    const countLabel = document.getElementById('reader-notes-count');

    if (!popover || !ctx.annotations) return;

    // The selection being acted on, or the existing annotation being edited.
    let pending = null;
    let editing = null;

    /* ------------------------------------------------------- selection */

    const removeButton = document.getElementById('reader-remove-highlight');

    const hidePopover = () => {
        popover.classList.add('hidden');
        pending = null;
        editing = null;
        removeButton?.classList.add('hidden');
    };

    const showPopoverAt = (anchor) => {
        popover.classList.remove('hidden');

        // Measured after unhiding, or the popover has no dimensions yet.
        const rect = popover.getBoundingClientRect();
        const margin = 8;

        let left = anchor.left + (anchor.width / 2) - (rect.width / 2);
        let top = anchor.top - rect.height - margin;

        // Flip below the selection when there's no room above it.
        if (top < margin) top = anchor.bottom + margin;

        left = Math.min(Math.max(left, margin), window.innerWidth - rect.width - margin);

        popover.style.left = `${Math.round(left)}px`;
        popover.style.top = `${Math.round(top)}px`;
    };

    const offerSelection = () => {
        // Deferred so the browser has committed the selection.
        setTimeout(() => {
            if (!popover.classList.contains('hidden') && pending) return;

            const captured = ctx.captureSelection?.();

            if (!captured) return;

            pending = captured;
            showPopoverAt(captured.anchor);
        }, 10);
    };

    // Selection is checked after the gesture finishes, not during — reading
    // it mid-drag gives a partial range.
    document.addEventListener('pointerup', offerSelection);

    // EPUB renders in an iframe whose events never reach this document, so the
    // renderer calls this directly when it sees a selection.
    ctx.requestSelectionPopover = offerSelection;

    document.addEventListener('pointerdown', (event) => {
        if (popover.contains(event.target)) return;
        if (noteEditor?.contains(event.target)) return;

        hidePopover();
    });

    /* --------------------------------------------------- colour buttons */

    popover.querySelectorAll('[data-highlight-color]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.stopPropagation();

            const color = button.dataset.highlightColor;

            if (editing) {
                await ctx.annotations.update(editing.id, { color });
                editing = null;
            } else if (pending) {
                await ctx.annotations.create({
                    location: pending.location,
                    page: pending.page,
                    excerpt: pending.excerpt,
                    color,
                });
            }

            window.getSelection()?.removeAllRanges();
            hidePopover();
            ctx.redrawHighlights?.();
        });
    });

    /* ----------------------------------------------------- note editing */

    const openNoteEditor = (annotation, excerpt) => {
        if (!noteEditor || !noteInput) return;

        noteEditor.classList.remove('hidden');
        noteInput.value = annotation?.note ?? '';
        noteInput.focus();

        const quote = document.getElementById('reader-note-excerpt');

        if (quote) quote.textContent = excerpt ?? annotation?.excerpt ?? '';
    };

    const closeNoteEditor = () => {
        noteEditor?.classList.add('hidden');
        editing = null;
    };

    document.getElementById('reader-add-note')?.addEventListener('click', async (event) => {
        event.stopPropagation();

        // A note needs something to attach to, so an un-highlighted selection
        // becomes a highlight first.
        if (pending && !editing) {
            const created = await ctx.annotations.create({
                location: pending.location,
                page: pending.page,
                excerpt: pending.excerpt,
                color: 'yellow',
            });

            if (created) editing = created;

            window.getSelection()?.removeAllRanges();
            ctx.redrawHighlights?.();
        }

        hidePopover();
        openNoteEditor(editing, editing?.excerpt);
    });

    document.getElementById('reader-note-save')?.addEventListener('click', async () => {
        if (!editing) return closeNoteEditor();

        await ctx.annotations.update(editing.id, { note: noteInput.value.trim() });

        closeNoteEditor();
        ctx.redrawHighlights?.();
    });

    document.getElementById('reader-note-cancel')?.addEventListener('click', closeNoteEditor);

    document.getElementById('reader-note-delete')?.addEventListener('click', async () => {
        if (!editing) return closeNoteEditor();

        await ctx.annotations.remove(editing.id);

        closeNoteEditor();
        ctx.redrawHighlights?.();
    });

    /* ------------------------------------------- tapping a highlight */

    ctx.onHighlightClick = (annotation, mark) => {
        editing = annotation;
        pending = null;

        // Removing only makes sense for something that already exists, so the
        // button appears here rather than on a fresh selection.
        removeButton?.classList.remove('hidden');

        // The popover doubles as an edit menu for an existing highlight.
        showPopoverAt(mark.getBoundingClientRect());
    };

    removeButton?.addEventListener('click', async (event) => {
        event.stopPropagation();

        if (!editing) return;

        const id = editing.id;

        hidePopover();
        window.getSelection()?.removeAllRanges();

        await ctx.annotations.remove(id);
        ctx.redrawHighlights?.();
    });

    /* ------------------------------------------------------- sidebar */

    const renderList = (items) => {
        if (countLabel) countLabel.textContent = items.length ? String(items.length) : '';

        if (!list) return;

        list.replaceChildren();

        if (items.length === 0) {
            emptyState?.classList.remove('hidden');

            return;
        }

        emptyState?.classList.add('hidden');

        items.forEach((annotation) => {
            const color = HIGHLIGHT_COLORS[annotation.color] ?? HIGHLIGHT_COLORS.yellow;

            const entry = document.createElement('li');
            entry.className = 'reader-note-entry';

            const jump = document.createElement('button');
            jump.type = 'button';
            jump.className = 'reader-note-jump';

            const swatch = document.createElement('span');
            swatch.className = 'reader-note-swatch';
            swatch.style.background = color.ink;

            const body = document.createElement('span');
            body.className = 'reader-note-body';

            const excerpt = document.createElement('span');
            excerpt.className = 'reader-note-excerpt';
            excerpt.textContent = annotation.excerpt ?? '';

            body.append(excerpt);

            if (annotation.note) {
                const note = document.createElement('span');
                note.className = 'reader-note-text';
                note.textContent = annotation.note;
                body.append(note);
            }

            if (annotation.page) {
                const page = document.createElement('span');
                page.className = 'reader-note-page';
                page.textContent = `Page ${annotation.page}`;
                body.append(page);
            }

            jump.append(swatch, body);
            jump.addEventListener('click', () => {
                if (annotation.page) ctx.goToPage?.(annotation.page);

                sidebar?.classList.add('hidden');
            });

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'reader-note-delete';
            remove.setAttribute('aria-label', 'Delete');
            remove.textContent = '×';
            remove.addEventListener('click', async () => {
                await ctx.annotations.remove(annotation.id);
                ctx.redrawHighlights?.();
            });

            entry.append(jump, remove);
            list.append(entry);
        });
    };

    ctx.annotations.onChange(renderList);
    renderList(ctx.annotations.items);

    document.getElementById('reader-notes-toggle')?.addEventListener('click', () => {
        sidebar?.classList.toggle('hidden');
    });

    document.getElementById('reader-notes-close')?.addEventListener('click', () => {
        sidebar?.classList.add('hidden');
    });
}
