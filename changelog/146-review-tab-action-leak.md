# 146 — The Merge action no longer leaks onto the Metadata review tab

The Review hub's tabs are different *kinds* of review with different actions —
the **Metadata** tab re-enriches an item the pipeline was unsure about; the
**Duplicates** tab merges a pair. `DuplicatesTable` switches the whole table
configuration (columns and actions) on the active tab.

But Filament's default `updatedActiveTab()` only resets the page — it does not
rebuild the table. So after visiting the Duplicates tab and switching back to
Metadata, the previous tab's table lingered, and **"Merge selected" showed up on
the Metadata tab**, where merging makes no sense (a metadata-flagged item is a
single track, not a duplicate pair).

## Fix

`ListDuplicates::updatedActiveTab()` now calls `resetTable()` after the parent,
so switching tabs rebuilds the table against the tab that is showing and its
actions match. Columns already updated correctly; only the actions were stale.

## Tests

`MetadataReviewTabTest`: a new case drives the exact sequence — Metadata (sees
Re-enrich, not Merge) → Duplicates (sees Merge) → back to Metadata (Merge gone
again). Full review-hub suite green.

## Not in this change

Separately noticed while investigating: ~100 tracks sit in the Metadata tab
flagged `NeedsReview` with `match_confidence: exact` and **no** `review_reason`.
They were flagged by MusicBrainz's `flagIfUncertain` (an exact *recording* match
whose chosen *release* is a compilation or undated) before the reason/provenance
system (S-277) existed, so the "why" was never captured and the UI falls back to
the misleading line "Flagged for review despite an exact match." That is a
separate issue — the fallback wording and a re-enrich pass to backfill the
reasons — tracked on its own.
