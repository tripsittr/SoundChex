# 119 — "Duplicates" becomes "Needs Review"

*2026-09-19.* · **Issue** S-268

## Done

The duplicate-review screen already reviews more than duplicates — cover art
lands there too, and more review types will — so it is renamed from
**Duplicates** to **Needs Review**, with the tabs as the review *types*.

- Nav label is now **Needs Review** (icon: clipboard-check), and its sidebar
  badge counts **all** review work — pending duplicates **plus** covers awaiting a
  look — rather than only pending duplicates, so the number reflects everything
  waiting.
- The tabs read as types: **Duplicates** and **Cover art** first (each with its
  own waiting count), then the resolved states (Keeping both, Merged) and All.
  The old first tab "Needs review" was confusing once the whole page carried that
  name.

The query, actions, grid and per-tab behaviour are unchanged — this is naming and
the badge.

## Worth knowing

- Room for more review types (e.g. unmatched metadata, failed transcodes) as new
  tabs under the same resource, each contributing to the badge.
- No schema change; nothing to migrate.

## Tests

PHP: **681 passing** (+3). New `NeedsReviewResourceTest`: the nav label is "Needs
Review"; the badge sums pending duplicates and cover reviews (and ignores kept /
plain rows); it is null when nothing is waiting. Existing duplicate tests
unchanged. Pint clean.
