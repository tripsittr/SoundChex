# 121 — The Review hub gains a Metadata tab, with provenance (S-277)

"Needs Review" meant two unrelated things in two places: the music list showed a
`NeedsReview`/`Failed` **processing status** (a metadata problem), while the
"Needs Review" page keyed off `duplicate_status` / `needs_cover_review`
(duplicate and cover problems). A track flagged in the music list could not be
found on the review page, because they were different systems sharing a name.

This folds metadata review into the one hub, and — following what Plex/Jellyfin
tooling (MetaTana) and Navidrome do — records **why** each item is flagged so a
reviewer can triage without opening every one.

## What this adds

- **A Metadata tab** on the Review hub (the ex-Duplicates resource, which was
  always meant to hold every review type). It lists items the pipeline could not
  identify confidently or flagged as an ambiguous match — the very items that
  showed "Needs Review" in the music list with nowhere to go.

- **The hub query now spans all review work.** It was
  `whereNotNull('duplicate_status')`, which excluded metadata items entirely; it
  now includes `needs_cover_review` and the `NeedsReview`/`Failed` statuses, so
  each tab's own filter has rows to find. The nav badge counts all three kinds.

- **Provenance capture.** A new `enrichment_report` on each item records what the
  last pipeline run did — per source: matched (with confidence), found nothing,
  errored, or skipped (and whether for a missing key) — plus a one-line
  `review_reason`. The sources are untouched: the outcome is inferred from the
  item's match confidence before and after each runs, so no source had to change.

- **A "Why?" action** shows that source-by-source chain in a modal, and the
  Metadata tab surfaces the one-line reason inline. Per-item and bulk actions to
  **re-enrich** (re-run the pipeline) and **mark reviewed** (accept as-is).

## Design notes

- One page, per-type tabs — the same structure the cover-review grid already
  uses (`$onCoverTab` → now `$onMetadataTab`), not a second resource. Audio
  review can slot in the same way later.
- `enrichment_report` is written by the pipeline via `forceFill`/`saveQuietly`
  and is not `$fillable` — like duplicate state, it is never set by a form.
- The report is captured on the next enrichment. Existing items show a sensible
  fallback reason from their `match_confidence` until they are re-enriched.

## Tests

- `EnrichmentReportTest` — records matched/no-match/errored/skipped-no-key
  outcomes and a review reason (fake sources).
- `MetadataReviewTabTest` — the tab lists flagged items and not clean ones;
  re-enrich queues the job; mark-reviewed clears the flag (driven through
  Livewire, which caught a missing import a query-level test would not have).
- `NeedsReviewResourceTest` — the badge and hub query include metadata items.
- Full suite: 711 passed.
