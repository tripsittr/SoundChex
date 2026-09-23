# 175 — Duplicates the strict pass was missing

**Merged** 2026-09-23 · **Issues** S-339

The library still showed songs twice — 9/10, American Idiot, Bitter Sweet
Symphony and many more — after duplicate detection had run. 78 groups of the
same artist and title were never flagged at all.

## What changed

### A second, looser pass that only ever asks

The content pass required the album to be **equal** and the length within
tolerance. That is the right bar for deciding a merge and too high for finding
everything worth a look: the same song on a greatest-hits or a soundtrack, or a
remaster a few seconds longer, was rejected outright.

Those now match as `DuplicateMatch::Likely` — flagged for review, never
auto-merged, because a different album *and* a different length sometimes
really is a separate recording. The strict `Fuzzy` match keeps its meaning and
its merge behaviour.

The length limit for the looser pass is 12 seconds. That covers a remaster, a
different fade, or a count-in, and deliberately stops short of a radio edit,
which trims thirty seconds or more and is a genuinely different cut.

## Worth knowing

- On the live library: **78 unflagged groups → 8**, and the 8 that remain are
  more than 12 seconds apart — ambiguous by design, not missed.
- **Nothing was deleted.** 8326 items before and after; the new matches sit in
  the review queue (52 likely, 19 fuzzy) for a person to decide.
- Two existing tests asserted the old strictness. One was kept as-is (a radio
  edit 30s apart is still not a duplicate); the other now expects a review flag
  rather than nothing, which is the point of this change.

## Still wrong

- Chained duplicate links (a duplicate pointing at another duplicate) still
  exist — 335 of them, up to 7 deep. They are hidden correctly today because
  the API filters on `duplicate_status = merged`, but the chains should be
  collapsed to one canonical row.
