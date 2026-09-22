# 157 — Reviewed songs stay reviewed through re-enrichment

Marking a metadata item "Looks fine" set it to Complete, which is
indistinguishable from an item the pipeline completed on its own. So when a
re-enrichment ran — especially a bulk "re-enrich the whole library" — the
pipeline would flag the same items for review again, and the songs you had
already cleared kept reappearing in the review queue.

## Changed

- **A human review is now recorded.** Clearing an item ("Looks fine", or the
  bulk "Mark reviewed") stamps a new `reviewed_at` on it — an explicit "a person
  has judged this", distinct from a pipeline-completed item.
- **Re-enrichment respects that decision.** When the pipeline flags an item for
  review but it carries `reviewed_at`, the enrichment job keeps it Complete
  instead of sending it back to the queue. So a background or bulk re-enrich no
  longer undoes your review work.
- **An explicit re-enrich re-opens review.** The "Re-enrich" action (single and
  bulk) clears `reviewed_at` first — you asked to re-check that item, so this run
  *may* surface a review again. Only background/bulk enrichment keeps the stamp
  and leaves a prior "Looks fine" alone.

## Testing

- `MetadataReviewTabTest` (5 pass): marking reviewed stamps `reviewed_at`; a
  re-enrichment whose pipeline flags review leaves a human-reviewed item Complete.
- Enrichment suite (17 pass) unaffected.

## Note

Items already cleared before this change have no `reviewed_at` yet, so the guard
protects reviews made from here on. Items the runaway bulk re-enrich pushed back
into the queue will stay cleared once re-reviewed.
