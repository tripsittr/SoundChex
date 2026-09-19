# 110 — Verify cover art after a merge

*2026-09-19.* · **Issue** S-265

## Done

Merging a content duplicate keeps one copy and deletes the other. The audio
choice is sound (bitrate → sample rate → tags → newer), but the *cover* is not
always: embedded art is unreliable — a track can carry a compilation's cover
(Stressed Out had "A Sky Full of Stars — Modern Pop" baked in), and the code
can't tell a wrong cover from a right one without fetching a verified one.

So the merge no longer guesses at artwork. It keeps the audio as before and, when
the two copies had **different** cover art, flags the surviving row for a human to
check:

- New `needs_cover_review` flag on media items (migration included). It is
  orthogonal to `duplicate_status` — the row stays **Merged**; this is a separate
  "look at the cover" marker.
- `DuplicateDetector::resolveKeeping()` sets it by comparing the two copies'
  extracted cover files by content hash. A difference we can actually see raises
  the flag; a matching cover, a missing cover, or a remote-URL cover does not —
  we never flag a maybe.
- **Library → Duplicates** gains a **"Verify cover art"** tab (with a count
  badge) listing exactly these rows, a small cover thumbnail column, and a
  **"Cover is fine"** row action that clears the flag.

Much of the wrong-cover problem already fixes itself here: when duplicate copies
have different art, keeping the better copy often keeps the better art too
(confirmed — Stressed Out's three copies merged to the one with the correct
Blurryface cover). This tab catches the rest.

## Worth knowing

- **Migration run here:** `add_needs_cover_review_to_media_items` (nullable
  boolean, default false, indexed). No data rewrite.
- This does **not** fetch a correct cover — it only surfaces the ones to check.
  Refetching a verified cover is issue S-258 (validated iTunes + Deezer), and a
  reviewer would trigger that from this queue once it lands.
- Only content merges can raise the flag; byte-identical merges keep one identical
  file, so there is nothing to verify.

## Tests

PHP: **629 passing** (+13 net). New in `ContentDuplicateDetectorTest`: the flag is
set when covers differ, not set when they match or when one copy has no art, and
`clearCoverReview()` unsets it. Existing duplicate tests unchanged and green.
Pint clean.
