/**
 * Keeping focus inside a dialog while it is open (S-442).
 *
 * `role="dialog"` with `aria-modal="true"` is a claim that the rest of the
 * page is unavailable. Visually it is — there is a backdrop over it — but
 * focus does not know that. Without this, opening a dialog leaves focus on the
 * button behind it, Tab walks out through the backdrop into a page the person
 * cannot see, and closing it drops focus to the body, losing their place
 * entirely.
 *
 * Deliberately small. `inert` on the rest of the page would be the tidier
 * answer, but these dialogs are siblings deep inside the layout rather than
 * children of `<body>`, so there is no single subtree to mark. Trapping Tab is
 * the part that matters.
 */

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

/** What can actually be focused right now — hidden items cannot. */
function focusable(root) {
    return [...root.querySelectorAll(FOCUSABLE)].filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
    );
}

/**
 * Traps focus within `dialog` until the returned function is called.
 *
 * @param {HTMLElement} dialog
 * @param {{ initial?: HTMLElement }} options
 * @returns {() => void} Releases the trap and restores focus.
 */
export function trapFocus(dialog, { initial } = {}) {
    // Where focus was, so it can go back there. Restoring to the button that
    // opened the dialog is the difference between closing one and being
    // dumped at the top of the page.
    const returnTo = document.activeElement;

    const onKeydown = (event) => {
        if (event.key !== 'Tab') return;

        const items = focusable(dialog);

        if (items.length === 0) {
            // Nothing to focus: hold it on the dialog rather than letting Tab
            // escape to the page behind.
            event.preventDefault();

            return;
        }

        const first = items[0];
        const last = items.at(-1);

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    document.addEventListener('keydown', onKeydown, true);

    // Into the dialog. The caller's choice first — usually the safe action
    // rather than the destructive one — then whatever comes first.
    (initial ?? focusable(dialog)[0] ?? dialog).focus?.();

    return () => {
        document.removeEventListener('keydown', onKeydown, true);

        if (returnTo instanceof HTMLElement && document.contains(returnTo)) {
            returnTo.focus();
        }
    };
}
