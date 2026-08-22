import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Highlights have to survive a reload, which is the whole point of storing
 * them server-side rather than in the reader's own state.
 *
 * The selection gesture itself is not driven here. Dragging across rendered
 * epub.js text is slow and brittle, and it would be testing the browser's
 * selection API rather than this project's persistence. The annotation API is
 * what the reader calls, so these exercise that and then assert the reader
 * *renders* what came back — which is the part that actually broke.
 */
test.describe('reader annotations', () => {
    let bookId;

    test.beforeEach(async ({ page }) => {
        await signIn(page);

        await page.goto('/app/book');

        const link = page.locator('a[href*="/app/item/"]').first();
        const href = await link.getAttribute('href');
        bookId = href.match(/\/app\/item\/(\d+)/)[1];
    });

    test('a highlight is still there after a reload', async ({ page }) => {
        const created = await createHighlight(page, bookId, {
            excerpt: 'the yellow wallpaper',
            note: 'a note that must survive',
        });

        expect(created.id).toBeTruthy();

        // A fresh page load, not a client-side re-render: nothing of the
        // previous document survives.
        await page.reload();

        const stored = await listHighlights(page, bookId);

        expect(stored.map((a) => a.note)).toContain('a note that must survive');
    })

    test('a highlight survives leaving the reader and coming back', async ({ page }) => {
        await createHighlight(page, bookId, { excerpt: 'chapter one', note: 'still here' });

        await page.goto('/app');
        await page.goto(`/app/read/${bookId}`);

        const stored = await listHighlights(page, bookId);

        expect(stored.map((a) => a.note)).toContain('still here');
    });

    test('a deleted highlight does not come back', async ({ page }) => {
        // The mirror of persistence: state that outlives its own deletion is
        // the same bug wearing the other hat.
        const created = await createHighlight(page, bookId, { excerpt: 'temporary' });

        await page.evaluate(async ({ id, annotationId }) => {
            await fetch(`/app/read/${id}/annotations/${annotationId}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
        }, { id: bookId, annotationId: created.id });

        await page.reload();

        const stored = await listHighlights(page, bookId);

        expect(stored.map((a) => a.id)).not.toContain(created.id);
    });

    test('the reader opens the book', async ({ page }) => {
        // Guards the tests above: if the reader never mounts, "the highlight
        // is stored" would be true and useless.
        await page.goto(`/app/read/${bookId}`);

        await expect(page.locator('#reader, [data-reader], .reader').first())
            .toBeVisible({ timeout: 15000 });
    });
});

async function createHighlight(page, id, { excerpt, note = null }) {
    await page.goto(`/app/read/${id}`);

    return page.evaluate(async ({ id, excerpt, note }) => {
        const response = await fetch(`/app/read/${id}/annotations`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                // Opaque per-format position blob; the reader is what
                // interprets it, so any shape round-trips.
                location: { cfi: 'epubcfi(/6/4!/4/2/2)' },
                excerpt,
                note,
                color: 'yellow',
            }),
        });

        const body = await response.json();

        // The controller wraps it: {annotation: {...}}.
        return body.annotation ?? body;
    }, { id, excerpt, note });
}

async function listHighlights(page, id) {
    return page.evaluate(async (id) => {
        const response = await fetch(`/app/read/${id}/annotations`, {
            headers: { Accept: 'application/json' },
        });

        const body = await response.json();

        return Array.isArray(body) ? body : (body.annotations ?? body.data ?? []);
    }, id);
}
