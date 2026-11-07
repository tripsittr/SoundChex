# 256 — Score the match before promoting the title

**Merged** 2026-10-05 · **Issues** #455

Every film and series matched by a title search was recorded as an *exact*
match, including the wrong ones. `Exact` is the one confidence allowed to
rename and move a video file.

## What changed

### Confidence is decided against what was searched, not what was returned

`Movie\Tmdb::enrich()` called `promoteTitle()` — which overwrites the item's
title with TMDB's — and *then* `recordConfidence()`, which decided `Exact` by
comparing TMDB's title to the item's title:

```php
$exact = $matchedById
    || strcasecmp(trim($canonical), trim((string) $item->title)) === 0;
```

After promotion those are the same string by construction, so `strcasecmp()`
returned 0 every time and the comparison could only ever say "exact". It was
comparing TMDB's title against itself. `Show\Tmdb::enrich()` had the same
order.

Both now capture the title *before* promotion and pass it in. The movie source
passes the **parsed** title, because that is what was actually searched: a
filename leaves the year in the title (`Inception 2010`) and `resolveMovie()`
splits it out before querying, so comparing against the raw title would
report a correctly-matched film as `Fuzzy` and stop it being filed.

The same file already captured `$matchedById` before `fillBlank()` writes an
id, with the comment "afterwards every match would look as though it had been
resolved by id". That is the identical mistake one step later; it is now
guarded the same way.

`recordConfidence()`'s new argument is optional, and when it is absent a match
not reached by an id is scored `Fuzzy` rather than `Exact` — an un-updated
caller is then pessimistic instead of silently confident.

## Worth knowing

- **Existing rows keep their recorded confidence.** Nothing is re-scored by
  this change. On the Mac that is 2 films; the Windows library has far more,
  and every one matched by title search currently reads `Exact` whether or not
  it is right.
- A re-scoring command is **not** in this change — it belongs with the scorer
  in #466, which can compare titles properly rather than exactly. Until then,
  a wrong match is still recorded as wrong; it is just no longer *created* as
  `Exact`.
- `LIBRARY_AUTO_ORGANIZE=false` remains the right setting until #456 lands
  (the admin toggle is inert), because existing false-`Exact` rows can still
  move files.
- Unrelated: the full suite cannot finish on this machine — `WorkerLogTest`
  exhausts the 512M `phpunit.xml` limit after ~1,330 tests. Verified
  pre-existing on untouched `main` and logged as #473.
  `--exclude-filter=WorkerLogTest` gives 1326 passed / 3 skipped / 0 failed.
