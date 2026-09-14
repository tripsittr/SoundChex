# 047 — Songs repeated across pages

**Merged** 2026-09-14 · **Issues** S-141

The songs grid duplicated tracks across pages and dropped others entirely. Found
on the phone, but it was never phone-specific — the same query serves the desktop.

## What was wrong

`MediaBrowser::grid()` ordered by `created_at` alone before paginating. That is
not a *total* order: **1,124 tracks in this library share a single
`created_at`** — a whole import lands in the same second — and a paginator over
a non-unique sort returns tied rows in whatever order the engine chooses, which
differs between the query behind page 1 and the query behind page 2. So a song
could appear on both, while another appeared on neither.

## The fix

A unique tiebreaker. `grid()` now orders by `created_at` then `media_items.id`,
so the total order is deterministic and every row falls on exactly one page.

The album and artist browse pages had the same latent bug in a milder form —
they order by `LOWER(artist)`/`LOWER(album)`, which ties when two names differ
only in case — so those gained tiebreakers on the exact group keys too.

## Worth knowing

- **Server-side, so it is already live** — no app rebuild needed. Reloading the
  songs tab picks it up.
- Verified against the real library: page 1 and page 2 now share **zero** rows
  (they overlapped before).

## Tests

`BrowsePaginationTest` (2): walking every page through the real `grid()` yields
each of 150 same-timestamp tracks exactly once, and the query orders by the
unique id. **A caveat, recorded honestly:** order *instability* is
luck-dependent, and SQLite in memory can return a stable slice for a small set
even without the tiebreaker — so the union test can pass on a masked bug. The
id-in-ORDER-BY assertion is the guard that does not depend on that luck. Full
PHP suite 570.
