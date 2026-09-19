# 113 — Music enrichment records that it matched

*2026-09-19.* · **Issue** S-273

## Done

The root cause behind poor music metadata: **all 8,313 tracks read as
`match_confidence = none`**, so the whole library looked unenriched — even though
the pipeline was actually fetching data (enriching "Stressed Out" found its
MusicBrainz recording id). The music sources wrote fields and covers but **never
set `match_confidence` or `matched_by`** — only the Movie/Show/Book sources did.
So nothing recorded that a track had been identified, `music:reenrich
--only-unmatched` treated the entire library as unmatched, and there was no
signal to build on.

Now the music sources record their match, mirroring the Movie/Show sources:

- **MusicBrainz** — **Exact** when reached by a recording id or ISRC (unambiguous
  identifiers), **Fuzzy** when reached by an artist+title search.
- **AcoustID** — **Exact** (an acoustic fingerprint identifies the actual
  recording).
- **iTunes** — **Fuzzy** (an artist-validated search), recorded only when nothing
  stronger has, so it lifts a `none` to at least Fuzzy where MusicBrainz found
  nothing.

None of them ever downgrades an Exact match a stronger source already pinned, and
each sets `matched_by` so the source is visible.

**Review still runs.** MusicBrainz's `flagIfUncertain` (which surfaces shaky
matches — compilations, undated releases — in the Needs Review flow) is unchanged
and still runs after the confidence is recorded. Confidence and review are
orthogonal: a Fuzzy match can also be flagged for review.

## Worth knowing

- **Re-run enrichment after deploying** — `php artisan music:reenrich` — so the
  existing library records its matches. On a 10-track sample it went from 0/10
  matched to **10/10** (4 Exact, 7 Fuzzy across the run), with 6 correctly flagged
  for review. New imports set it automatically.
- This is expected to be the reason several things looked broken — titles keeping
  the artist (S-272), wrong covers (S-258), the library feeling unenriched — since
  nothing downstream trusted the matches. Re-enriching is the next step to
  actually apply the improved data.
- No schema change.

## Tests

PHP: **655 passing** (+3). New `MusicMatchConfidenceTest`: MusicBrainz records
Exact by id and Fuzzy by search; iTunes records Fuzzy but never downgrades an
Exact. Existing enrichment/credits tests (17) unchanged and green. Pint clean.
