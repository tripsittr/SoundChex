# 111 — Fetch verified album covers (fix mismatched artwork)

*2026-09-19.* · **Issue** S-258

## Done

Some tracks carry the wrong cover: the art embedded in the file is often a
compilation's, not the album's (Stressed Out had "A Sky Full of Stars — Modern
Pop" baked in), and the old iTunes lookup took the first search result blindly,
so a loose term match could attach another artist's cover. A new
`artwork:refresh` command replaces music covers with a **verified** album cover
fetched online.

### Verified

- `CoverArtFetcher` looks an album up on iTunes and accepts a result **only when
  its artist matches** the album's — normalised (case, "the", punctuation) and
  allowing "Beatles" ≈ "The Beatles", "Weezer" ≈ "Weezer feat. …". A mismatch
  returns nothing rather than the wrong cover, so a track with no confident match
  keeps whatever it had.
- The same validation was applied to the pipeline's `ItunesSearch` source, so new
  imports stop attaching wrong covers too.

### Efficient (the explicit ask)

Covers belong to the album, not the track, so the fetch is **strictly per
album**: the command groups the library by artist+album and looks each album up
once, sharing the result across its tracks. On the dev library that's **~4,095
calls for 8,313 tracks** instead of one per track. The fetcher also memoises per
run, stores **one file per unique image** (so an album's tracks share a cover
file rather than writing thousands of copies), and throttles between calls
(`library.cover_fetch_throttle_ms`, default 200 ms).

### Scoped

`artwork:refresh --scope=`:

- **`likely-wrong`** (default) — only tracks whose album tag reads like a
  compilation/playlist. On the dev library: **278 tracks / ~178 calls** — the
  actual problem, cheaply.
- **`missing`** — only tracks with no cover (8 tracks / ~7 calls here).
- **`all`** — every track (8,313 / ~4,095 calls).

Dry-runs by default (prints the track/album/call counts); `--force` to act,
`--sample=N` for a trial over N albums. Hand-picked covers (uploaded to
`media/covers/`) are never touched; only the per-track extracted `artwork/…`
files are replaced.

## Worth knowing

- **Not run yet** — this ships the tool. Suggested first run:
  `php artisan artwork:refresh --scope=likely-wrong --sample=10 --force` to eyeball
  results, then the full `--scope=likely-wrong --force`.
- iTunes only for now; Deezer as a second source (for compilation-tagged tracks
  that iTunes can't match by album) is a follow-up integration (S-259).
- A cover-review gallery that shows the fetched reference next to the kept cover
  builds on this (S-266).

## Tests

PHP: **640 passing** (+15). `CoverArtFetcherTest` proves the verified match, the
wrong-artist rejection, and **one API call per album, not per track**.
`RefreshArtworkCommandTest` covers each scope, the shared-per-album fetch, the
hand-picked-cover guard, and keeping the existing cover on no match.
`ItunesArtworkMatchTest` covers the pipeline source's validation. Pint clean.
