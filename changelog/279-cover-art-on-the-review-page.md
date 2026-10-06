# Cover art on the review page

Reported: cover art is not showing on the review page. Reported **again** after
the first fix, with a screenshot — because the first fix only covered half the
screen.

Three faults, all in the view.

**The queue list drew an icon for every row.** The sidebar — the part actually
visible in the screenshot — hardcoded a music-note or film icon per row and
never looked at the artwork at all. So a queue of 89 cover-art questions showed
89 identical music notes, in the one view where the artwork *is* the thing being
judged. Fixing the detail pane alone left the screen looking unchanged, which is
how the owner found it still broken. On the live library that list now renders
**76 images, 73 of them real artwork**, with 6 placeholders for items that
genuinely have no cover.

**The cover was drawn only in the cover-art job.** The `<img>` sat inside
`@if ($job === ReviewQueue::COVERS)`, so artwork appeared on exactly one of the
four queues. On this library that queue holds **nothing**, while *identify* holds
38 items and *duplicates* holds 6 — every one rendered as text, with valid
artwork sitting on disk the whole time. Measured before the fix: the identify and
duplicate queues emitted **zero** `<img>` tags.

Recognising a record by its sleeve is most of how somebody answers "what is
this?", so the cover now sits beside the title on **every** job.

**The URL was not encoded.** The view called
`Storage::disk('public')->url($item->cover_image_url)` directly, which does not
escape the path — and these paths are built from artist and album names. The
model already has `coverUrl()`, which `rawurlencode`s each segment, and the view
simply was not using it. Compare:

```
coverUrl()     …/storage/artwork/%24uicideboy%24%2C%C2%A0Germ/Unknown%20Album/…
Storage::url() …/storage/artwork/$uicideboy$, Germ/Unknown Album/…   ← never fetched
```

That `%C2%A0` is worth noting: one artist directory contains a **NO-BREAK SPACE
(U+00A0)**, not a normal space, so there are two directories that look identical
on screen. The database row pointed at the right one all along — a plain
`str_replace(' ', '%20')` would have missed it, which is why it has its own test.

**Duplicates now show both covers.** "Is this the same record?" is answered by
eye faster than by comparing two file paths, and differing artwork is often the
clearest sign two copies are different releases rather than duplicates.

## Verified

- Rendered against the **live library**: identify and duplicates went from 0
  images to showing artwork, duplicates renders **3** (title plus both sides of
  the comparison), and no `src` attribute contains an unencoded space.
- The NBSP path serves **HTTP 200** through `coverUrl()`'s encoding.
- 1559 passed, 3 skipped, 0 failed (whole suite bar `WorkerLogTest`, which OOMs
  on this machine regardless of branch).
- Five new tests, four of which **fail against the original view** — checked by
  reverting only the Blade file and re-running. The fifth (no empty `src`) passes
  either way and guards a future regression rather than this bug.

## Not changed

Nothing in the model or the database: `coverUrl()` was already correct and all
8,320 rows with artwork point at files that exist. An earlier reading of mine
that covers were missing from disk was wrong — a shell-escaping artifact in my
own check, where `$uicideboy$` lost its `$` characters.
