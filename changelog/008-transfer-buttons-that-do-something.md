# 008 — Two buttons that rendered and did nothing

**Merged** pending

Cancel and Delete shipped in 007 with nine passing tests behind them. Neither
did anything when clicked.

## What changed

### The confirmation, not the buttons

Both used `wire:confirm`. The methods behind them worked, the rendered markup
carried the right `wire:click="cancel(1)"`, Livewire could call them, and in a
browser nothing happened.

`wire:confirm` is the only thing those two buttons had that every working
button on the page did not — and grepping the application found **no other use
of it anywhere**. It is implemented in the installed Livewire, so this is not a
proven diagnosis; it is the one difference, removed.

What replaced it is a second click held in component state: the first click
arms the row, the second does the work. That is the same round trip as Pause,
Resume and Check for approval — the buttons that demonstrably work — and it has
the side benefit of being drivable from a PHP test, which a native browser
dialog is not.

```
Cancel  →  Stop it on both machines | Keep it
Delete  →  Remove it | Keep it
```

The confirmation is per row. Arming one does not arm the others, which matters
on a list of near-identical addresses.

### The actual failure: nothing drove the page

`TransferCancelTest` tested `cancel()` and `discard()` and passed the whole
time both buttons were dead. A test that calls the service is not a test of the
button that calls it, and there were no Livewire tests in this project at all.

`ServerTransferPageTest` now drives the component the way the browser does —
arming, confirming, backing out, and the per-row scoping. Removing the
confirmation step turns two of them red.

## Worth knowing

- **`color="gray"` renders no colour classes at all** in Filament v5.6 —
  `class="fi-btn fi-size-sm"` and nothing else. The existing Pause button has
  the same, so this is pre-existing and cosmetic, not from this change. Noted
  because it looked like a lead and was not.
- **Six PHP tests have been failing on Windows for some time**, comparing `/`
  against `\` in stored paths, plus one writing to a macOS-only log path.
  Confirmed identical on an unmodified tree. Filed as **S-86** — while the
  suite is red by default, a real failure cannot be told from the noise.
- **Films and shows are being classified wrongly** — reported, not yet
  diagnosed, filed as **S-85**.

## Tests

**385 PHP · 87 Vitest.** 6 failures and 1 error, all pre-existing and all
S-86 — confirmed identical on an unmodified tree before this change.

The confirmation step was removed on purpose and two tests went red.

**Playwright still has not run.** There is no `.env.e2e` on this machine and
`bootstrap.sh` refuses without scratch paths. The spec is updated to the
two-click flow and syntax-checked, and remains **unverified**.

**So the fix itself is unverified in a browser** — which is exactly how the bug
in 007 shipped. What is different this time is that the page is under test at
all, and that the mechanism now matches buttons known to work in this app. If
Cancel and Delete still do nothing, `wire:confirm` was not the cause and the
next place to look is the browser console on that page.
