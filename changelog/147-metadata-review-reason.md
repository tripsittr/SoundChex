# 147 — Metadata review reason names the real cause, not "despite an exact match"

Tracks matched exactly by MusicBrainz can still land in the Metadata review tab:
`flagIfUncertain` flags an item when the recording matched confidently but the
*release* it was placed on looks like a compilation or has no date (MusicBrainz
has no notion of "the famous studio version"). The review reason shown for these
was misleading in two ways:

- The UI fallback read **"Flagged for review despite an exact match."** — which
  sounds like a contradiction rather than "the album is in doubt".
- The pipeline's own reason for a flagged item was the generic **"A source found
  more than one likely match and left it for a human"** — wrong here; there was
  no ambiguity, just a doubtful release.

## Fixed

- `MusicBrainz::flagIfUncertain` now records a **specific** reason when it flags —
  compilation vs undated release — via a transient `reviewReasonHint` on the item.
- `MetadataPipeline` prefers that hint when composing a run's `review_reason`, so
  a freshly enriched item carries the real cause.
- The admin's fallback line (for items enriched before reasons existed) now reads
  **"Recording matched, but the album may be a compilation — confirm the
  release."** instead of the contradictory wording.

## Backfilling existing items

~100 tracks were flagged before reasons were recorded (`review_reason: null`), so
they only get the improved fallback line. Re-enriching them captures a real
reason (or clears the item if MusicBrainz now resolves it cleanly). The Metadata
tab's existing **Re-enrich selected** bulk action does this — select the items and
run it; no new tooling needed.

## Not changed

The flagging behaviour itself is unchanged and deliberate — an exact recording on
a compilation/undated release is worth a human glance. This is about naming *why*,
not about flagging less.

## Tests

`MusicMatchConfidenceTest`: a new case — an exact by-id match whose only release
is a compilation is recorded `Exact` **and** flagged `NeedsReview` with a reason
that names the compilation. Metadata/enrichment/review suites green (99).
