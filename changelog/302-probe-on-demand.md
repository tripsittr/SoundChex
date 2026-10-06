# Measure a file before deciding how to play it

From a5's review of #304, which closed the extension-only playability hole and
left one window open:

> an **unprobed HEVC `.mp4`** is still reported direct-playable and will fail
> on-device until the hourly probe backfill reaches it — the exact failure this
> fixes, just deferred.

Correct. Judging an unmeasured file on its container alone is optimistic in the
one direction that hurts, and "eventually" is the whole viewing somebody is
trying to start now.

## Probe on demand

`StreamPolicy::decide()` now measures a video nobody has measured, then decides
on its codecs. ffprobe reads a header rather than a file — well under a second
even for a large rip — and it happens once: the row is written and every later
decision reads it.

Failure is quiet by design. No ffprobe, a file on another machine, a format it
refuses: the item stays unmeasured and the decision falls back to the
container, which is exactly the behaviour that existed before. A playback
request must not fail because a measurement did.

## Two paths that were deciding blind

a5's second point was that the answer depends on whether the caller
eager-loaded `probe`. Auditing every caller of `isPlayableVideo()` found two
that did not:

- **`WatchController`** gates the web player on it, so an `.mp4` of HEVC passed
  the gate and loaded a player showing a black rectangle.
- **`MediaTranscoder::needsConversion()`** decides whether to *queue* a
  conversion — so an optimistic answer meant no conversion was ever queued for
  precisely the files that needed one.

Both now load the relation.

## Testing

3 new tests, **369 pass**. The first two encode real HEVC and H.264 files and
drive the actual endpoint, because the point is what gets *measured* — a
fixture would have asserted the plumbing while the behaviour stayed wrong.
Confirmed to fail without the change.

The third covers a file that cannot be measured at all, which must still return
a decision rather than erroring.
