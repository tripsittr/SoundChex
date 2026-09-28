# Kebab menus by keyboard, and a measured contrast pass

S-442, second piece. The first covered the player.

## The menus claimed a role they did not honour

The kebab menus are `<details>`/`<summary>`, which is a good choice — the
browser gives open, close, focus and Escape for free, and it keeps working
with JavaScript disabled. Two things it does not give, and neither had been
added:

- **`aria-expanded` never changed.** The trigger said `aria-haspopup="menu"`
  and then never reported whether the menu was open. A screen reader announced
  "More options, menu pop-up" identically in both states.
- **Arrow keys did nothing.** `role="menu"` *means* the arrows move between
  items; that is what the role promises anyone navigating by keyboard. Tab
  happened to walk the items, since they are ordinary buttons — and then
  walked straight out of the menu into the page behind it.

`menu-keyboard.js` adds arrow, Home and End movement with wrap-around, opens
from the trigger with Down or Up focusing the first or last item, closes on
Tab out, and returns focus to the trigger on Escape rather than dropping it to
the body. Delegated from the document, because rows are rebuilt constantly —
by Livewire, by the offline shell, by the download queue repainting — and a
per-menu listener would be lost on every repaint.

The other two popup triggers were already correct: the account menu is Alpine
with a bound `aria-expanded`, and the captions button sets it from JavaScript.

## Contrast, measured

The ink ramp passes on every surface it is used against:

| | base-900 | base-800 | base-700 |
|---|---|---|---|
| ink-100 | 18.20 | 17.39 | 16.38 |
| ink-300 | 10.15 | 9.70 | 9.13 |
| ink-500 | 5.86 | 5.60 | 5.28 |

The worst case in use is 5.28:1, comfortably past AA's 4.5:1 for body text.

**Two things were wrong.**

`--color-ink-400` was used but never defined — so that text fell back to
whatever it inherited, which is not a contrast ratio anyone chose. Now defined
midway between 300 and 500, at 7.03:1 worst case.

The accent measures **4.23:1 on base-900 and 3.80:1 on base-700** — under what
body text needs, though perfectly fine for the borders, fills and large type
it is mostly used for. Three places had it carrying words: the hero eyebrow,
the show eyebrow and the AGPL link. They use a new `--sc-accent-text`, the
same hue lifted until it clears 4.5:1 on every surface (4.78:1 at worst).

The two thresholds are different — 3:1 for large text and UI shapes, 4.5:1 for
body — and conflating them is how this goes wrong in both directions.

## Focus, in and out of dialogs

`role="dialog"` with `aria-modal="true"` claims the rest of the page is
unavailable. Visually it is — there is a backdrop. Focus did not know that.

The **remove-all confirmation** left focus on the button behind it, Tab walked
out through the backdrop into a page the person could not see, and closing
dropped focus to the body. It now traps Tab, opens on **Cancel** rather than
Remove (this deletes every download on the device; the keyboard should not
land on the destructive button), and returns focus to the trigger on close.

The **now-playing sheet** was half right already: it applies `inert` when
hidden, which is the better answer than a trap and keeps focus out of a
dialog that is off-screen. But nothing moved focus *into* it on open, so a
screen reader stayed on the bar behind an open full-screen dialog. It now
focuses Close — the way out, and the control someone needs to find first —
and restores focus on the way back.

`focus-trap.js` is deliberately small. `inert` on the rest of the page would
be tidier, but these dialogs are siblings deep in the layout rather than
children of `<body>`, so there is no single subtree to mark.

## Still open on S-442

The Filament admin surfaces, which come with their own framework-level
behaviour and are worth auditing separately.
