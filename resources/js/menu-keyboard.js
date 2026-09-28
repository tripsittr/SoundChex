/**
 * Keyboard handling for the kebab menus (S-442).
 *
 * The menus are `<details>`/`<summary>`, which is a good choice: the browser
 * gives open and close, focus and Escape for free, and it keeps working with
 * no JavaScript at all. Two things it does not give:
 *
 *   - **`aria-expanded` never changes.** The `<summary>` says
 *     `aria-haspopup="menu"` and then never reports whether the menu is open.
 *     A screen reader announces "More options, menu pop-up" identically
 *     whether the menu is showing or not.
 *   - **Arrow keys do nothing.** `role="menu"` is a promise that the arrow
 *     keys move between items — that is what the role *means* to anyone
 *     navigating by keyboard. Tab happened to walk the items because they are
 *     ordinary buttons, but it also walked straight out of the menu and into
 *     the page behind it.
 *
 * Delegated from the document, because rows are rebuilt constantly — by
 * Livewire, by the offline shell, by the download queue repainting — and a
 * listener bound per menu would be lost on every repaint.
 */

/** Every enabled item in a menu, in the order they are shown. */
function itemsOf(menu) {
    return [...menu.querySelectorAll('[role="menuitem"]')].filter(
        (item) => !item.disabled && item.offsetParent !== null,
    );
}

export function bindMenuKeyboard() {
    // `toggle` fires for every <details>, including ones that are not menus.
    document.addEventListener(
        'toggle',
        (event) => {
            const details = event.target;

            if (!(details instanceof HTMLDetailsElement)) return;

            const summary = details.querySelector(':scope > summary');

            if (!summary?.hasAttribute('aria-haspopup')) return;

            summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
        },
        true, // `toggle` does not bubble.
    );

    document.addEventListener('keydown', (event) => {
        const menu = event.target.closest?.('[role="menu"]');
        const summary = event.target.closest?.('summary[aria-haspopup]');

        // Opening from the trigger, with the first or last item focused —
        // which is what Down and Up mean on a closed menu button.
        if (summary && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            const details = summary.parentElement;

            if (!(details instanceof HTMLDetailsElement)) return;

            event.preventDefault();
            details.open = true;

            const items = itemsOf(details.querySelector('[role="menu"]') ?? details);

            (event.key === 'ArrowDown' ? items[0] : items.at(-1))?.focus();

            return;
        }

        if (!menu) return;

        const items = itemsOf(menu);

        if (items.length === 0) return;

        const at = items.indexOf(document.activeElement);

        const move = {
            ArrowDown: () => items[(at + 1) % items.length],
            ArrowUp: () => items[(at - 1 + items.length) % items.length],
            Home: () => items[0],
            End: () => items.at(-1),
        }[event.key];

        if (move) {
            // Otherwise the arrows scroll the page out from under the menu.
            event.preventDefault();
            move()?.focus();

            return;
        }

        // Tab out closes, rather than leaving an open menu floating over a
        // page the focus has already left.
        if (event.key === 'Tab') {
            menu.closest('details')?.removeAttribute('open');
        }
    });

    // Escape returns focus to the trigger. The browser closes the menu on its
    // own, but drops focus to the body, which loses someone's place entirely.
    document.addEventListener('keyup', (event) => {
        if (event.key !== 'Escape') return;

        const details = event.target.closest?.('details');

        if (details && !details.open) {
            details.querySelector(':scope > summary')?.focus();
        }
    });
}
