// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { logFailure } from './log.js';

/**
 * "Send for review" from the track menu (S-399).
 *
 * Two steps, deliberately. Reporting an item hides it from the library until
 * an admin clears it (S-396), which is a large consequence for one tap in a
 * menu whose neighbours are "Play next" and "Add to queue". So the first
 * dialog asks whether they meant it and says plainly what will happen; only
 * then does the second ask why.
 *
 * Delegated on `document` and bound once, like the rest of the track-menu
 * handlers: the rows are rendered per page and swapped by the SPA navigation,
 * so per-element binding would miss anything rendered later.
 */

const REASONS = [
    { value: 'metadata', label: 'Wrong metadata', hint: 'Wrong title, artist, album, year or genre' },
    { value: 'file', label: 'File problem', hint: 'Wrong file, bad quality, or it will not play' },
    { value: 'cover', label: 'Wrong cover', hint: 'Missing artwork, or artwork from something else' },
    { value: 'duplicate', label: 'Duplicate', hint: 'This is already in the library' },
    { value: 'other', label: 'Something else', hint: 'Anything the other reasons do not cover' },
];

function setupSendForReview() {
    if (window.soundchexReviewBound) return;

    window.soundchexReviewBound = true;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-send-for-review]');

        if (!button) return;

        event.preventDefault();

        // Close the menu first, so the dialog is not competing with an open
        // popover behind it.
        button.closest('details')?.removeAttribute('open');

        const id = button.dataset.sendForReview;
        const title = button.dataset.title || 'this item';

        confirmThenAsk(id, title);
    });
}

/**
 * Step one: do you mean it, knowing what it does.
 *
 * `confirm` rather than a styled dialog because the answer to "are you sure"
 * should be the browser's most boring, least dismissable-by-accident control.
 * The consequence is stated in the first sentence, not buried after the
 * question.
 */
function confirmThenAsk(id, title) {
    const sure = window.confirm(
        `Send “${title}” for review?\n\n`
        + 'It will be hidden from your library until an admin has looked at it. '
        + 'Nothing is deleted — it comes back once the review is cleared.',
    );

    if (!sure) return;

    openReasonDialog(id, title);
}

/**
 * Step two: why.
 *
 * A real <dialog>, so Escape closes it, focus is trapped, and the backdrop is
 * the browser's own. Built here rather than rendered into every page for the
 * same reason the download toast is: these rows appear in lists the offline
 * shell builds too, and page-anchored markup would be missing from half of
 * them.
 */
function openReasonDialog(id, title) {
    const dialog = document.createElement('dialog');
    dialog.className = 'review-dialog';

    const options = REASONS.map((reason, index) => `
        <label class="review-reason">
            <input type="radio" name="reason" value="${reason.value}" ${index === 0 ? 'checked' : ''}>
            <span>
                <strong>${reason.label}</strong>
                <small>${reason.hint}</small>
            </span>
        </label>
    `).join('');

    dialog.innerHTML = `
        <form method="dialog" class="review-form">
            <h2>What is wrong with “${escapeHtml(title)}”?</h2>
            <div class="review-reasons">${options}</div>
            <label class="review-note">
                <span>Anything else worth saying (optional)</span>
                <textarea name="note" maxlength="1000" rows="3"
                          placeholder="The more specific, the faster it gets fixed."></textarea>
            </label>
            <menu>
                <button value="cancel" class="review-cancel">Cancel</button>
                <button value="send" class="review-send">Send for review</button>
            </menu>
        </form>
    `;

    document.body.append(dialog);
    dialog.showModal();

    dialog.addEventListener('close', () => {
        if (dialog.returnValue === 'send') {
            const form = dialog.querySelector('form');

            send(id, {
                reason: form.elements.reason.value,
                note: form.elements.note.value.trim() || null,
            });
        }

        dialog.remove();
    });
}

async function send(id, body) {
    try {
        const response = await fetch(`/api/v1/items/${id}/review`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(body),
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        toast('Sent for review. It is hidden until an admin clears it.');

        // The item has just left the library, so the page behind is now
        // showing something that is no longer there. Reload rather than
        // surgically removing the row: the item may appear in several places
        // on one page (a rail, a grid, the now-playing bar) and a partial
        // removal would leave the others lying.
        setTimeout(() => window.location.reload(), 1200);
    } catch (error) {
        logFailure('send-for-review', error, { itemId: id });
        toast('Could not send that for review.');
    }
}

function escapeHtml(value) {
    const span = document.createElement('span');
    span.textContent = value;

    return span.innerHTML;
}

function toast(message) {
    let host = document.getElementById('soundchex-toast');

    if (!host) {
        host = document.createElement('div');
        host.id = 'soundchex-toast';
        host.className = 'toast';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.append(host);
    }

    host.textContent = message;
    host.dataset.visible = 'true';

    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { host.dataset.visible = 'false'; }, 3200);
}

export { setupSendForReview };
