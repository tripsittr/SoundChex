# The admin surfaces, audited

S-442, third and last piece.

## What the audit found

Very little, which is the honest result. Across the 15 custom admin views —
Filament's own components are upstream and out of scope here:

- **No unlabelled icon-only buttons.** Zero.
- **No clickable `<div>`s or `<span>`s.** Every control is a real button, so
  it is reachable, focusable and announced without any ARIA at all. That is
  the single most common screen-reader failure in an admin panel and it simply
  is not present.
- **No heading skips** anywhere in the media center views either — h1 to h3
  with nothing in between is how a screen reader's outline stops being useful,
  and the order is clean.
- **Every checkbox** is wrapped in its `<label>`, which associates it with no
  `for`/`id` needed.

## The one real fault

Three text inputs on the server-transfer page — the source address and two
password fields — had a `<label>` as a *sibling* rather than a wrapper, with
no `for` pointing at them. A label that is merely next to an input is not
attached to it: a screen reader reads an unlabelled password box, and clicking
the label does not focus the field.

Each now has an `id` and a matching `for`. Re-ran the audit afterwards: zero
unassociated inputs remain.

## What is deliberately not here

Filament's own form, table and modal components. They ship their own
accessibility behaviour, that behaviour changes with the framework version,
and patching it from this repo would be undone by the next upgrade. If a
Filament component turns out to have a real gap, the fix belongs upstream.
