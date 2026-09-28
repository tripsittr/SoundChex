# The web player's seek bars were sliders in name only

S-442, first piece.

## What the audit found

Less than expected, which is worth saying plainly. The media center already
has landmarks, a skip link, `aria-label` on every transport control, and
labels that update with state — play becomes pause, shuffle names its mode.
Of 86 Blade views, only one button was genuinely unlabelled (the Jetstream
hamburger, now labelled and carrying `aria-expanded`). My detector flagged two
more, both false positives with visible text.

## What was actually broken

Both seek bars — the docked one and the one in the expanded sheet — declared
`role="slider"` and then provided nothing a slider needs:

- **No `aria-valuenow`, `aria-valuemin` or `aria-valuemax`.** A screen reader
  announced "Seek, slider" and no position whatsoever. Zero occurrences of
  `aria-value*` existed anywhere in the JavaScript.
- **No `tabindex`.** The keyboard could not reach them at all. They were
  mouse-only, which makes the role a false claim rather than an incomplete
  one.

A `role="slider"` with neither a value nor a tab stop is worse than no role:
it promises a control that does not work.

## What changed

Both bars now carry the full range, a live `aria-valuenow`, and an
`aria-valuetext` spoken as durations — "3 minutes 7 seconds of 1 hour 52
minutes". `formatTime` gives "3:07", which a screen reader reads as a clock
time rather than a length, so `spokenTime` was added beside it in `player.js`.

Both are keyboard-operable with the keys the ARIA practices specify, so they
are the ones someone will already try: arrows for five seconds, shift for a
minute, PageUp/PageDown for a minute, Home and End for the ends.
`preventDefault` on the handled keys, since arrows otherwise scroll the page —
the opposite of what someone focused on a slider means.

## Track changes are announced

When the queue moved on, nothing said so. Sighted, the title becomes a
different title; without the screen there was no signal at all. A visually
hidden `role="status"` region now carries the new track and artist.

It is a separate element rather than a live region wrapped around the title,
because that container also holds the elapsed time — which would re-announce
every second. And it fires only on a real track change, not on the restore
path that runs at every page load, which would announce something nobody did.

## Not done yet

This is the player only. Still open under S-442: the library and admin
surfaces, focus management in modals and kebab menus, live regions for toasts,
and a measured contrast pass across the web theme.
