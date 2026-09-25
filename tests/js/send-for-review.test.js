// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { setupSendForReview } from '../../resources/js/send-for-review.js';

/**
 * Sending an item for review from the track menu (S-399).
 *
 * The consequence is the reason these tests exist: reporting hides the item
 * from the library until an admin clears it. A menu whose neighbours are
 * "Play next" and "Add to queue" must not hide someone's music on one
 * mis-tap, so the confirm is not decoration — it is the feature.
 */
describe('send for review', () => {
    let fetchMock;

    beforeEach(() => {
        document.querySelectorAll('dialog').forEach((d) => d.remove());
        document.body.innerHTML = `
            <details class="track-menu" open>
                <button data-send-for-review="42" data-title="A Song">Send for review</button>
            </details>
            <meta name="csrf-token" content="test-token">
        `;

        // Bound once per page in real use; the flag has to be cleared or the
        // second test in this file would register no handler.
        window.soundchexReviewBound = false;

        fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 201 });
        vi.stubGlobal('fetch', fetchMock);

        // jsdom implements <dialog> but not showModal in every version.
        if (!HTMLDialogElement.prototype.showModal) {
            HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
        }

        setupSendForReview();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.querySelectorAll('dialog').forEach((d) => d.remove());
    });

    function clickReport() {
        document.querySelector('[data-send-for-review]').click();
    }

    it('asks for confirmation before anything else happens', () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        clickReport();

        expect(confirmSpy).toHaveBeenCalledOnce();
        expect(document.querySelector('dialog')).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('says the item will be hidden, in the confirmation itself', () => {
        // The warning is the whole point of the step. If it stops saying what
        // happens, the confirm becomes a speed bump rather than informed
        // consent.
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        clickReport();

        const message = confirmSpy.mock.calls[0][0];

        expect(message).toContain('hidden from your library');
        expect(message).toContain('Nothing is deleted');
        expect(message).toContain('A Song');
    });

    it('declining sends nothing at all', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        clickReport();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('offers every reason once confirmed', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        clickReport();

        // Scoped to the dialog this click opened: `document.body.innerHTML`
        // in beforeEach does not remove dialogs appended to body by earlier
        // tests, so a global query here counts every one of them.
        const dialog = document.querySelector('dialog');
        const values = [...dialog.querySelectorAll('input[name="reason"]')]
            .map((input) => input.value);

        expect(values).toEqual(['metadata', 'file', 'cover', 'duplicate', 'other']);
    });

    it('posts the chosen reason and note', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        clickReport();

        const dialog = document.querySelector('dialog');
        dialog.querySelector('input[value="file"]').checked = true;
        dialog.querySelector('textarea').value = '  Plays as silence  ';

        dialog.returnValue = 'send';
        dialog.dispatchEvent(new Event('close'));

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());

        const [url, options] = fetchMock.mock.calls[0];

        expect(url).toBe('/api/v1/items/42/review');
        expect(options.method).toBe('POST');
        expect(options.headers['X-CSRF-TOKEN']).toBe('test-token');
        expect(JSON.parse(options.body)).toEqual({
            reason: 'file',
            note: 'Plays as silence',
        });
    });

    it('sends no note when the box is left empty', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        clickReport();

        const dialog = document.querySelector('dialog');
        dialog.returnValue = 'send';
        dialog.dispatchEvent(new Event('close'));

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());

        expect(JSON.parse(fetchMock.mock.calls[0][1].body).note).toBeNull();
    });

    it('cancelling the reason dialog sends nothing', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        clickReport();

        const dialog = document.querySelector('dialog');
        dialog.returnValue = 'cancel';
        dialog.dispatchEvent(new Event('close'));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('escapes the title rather than writing it into the markup', () => {
        // The title comes from the catalogue, which is built from filenames
        // and whatever a metadata provider returned.
        document.querySelector('[data-send-for-review]')
            .dataset.title = '<img src=x onerror=alert(1)>';

        vi.spyOn(window, 'confirm').mockReturnValue(true);

        clickReport();

        const dialog = document.querySelector('dialog');

        expect(dialog.querySelector('img')).toBeNull();
        expect(dialog.querySelector('h2').textContent).toContain('<img src=x');
    });

    it('closes the menu it was opened from', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        clickReport();

        expect(document.querySelector('.track-menu').hasAttribute('open')).toBe(false);
    });
});
