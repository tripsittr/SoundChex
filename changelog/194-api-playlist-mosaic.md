# 194 — The playlists API sends track covers

**Merged** 2026-09-24 · **Issues** S-371

On iOS, every playlist without a cover of its own showed a note glyph on the
playlists list — while opening that same playlist showed the usual 2×2 mosaic
of its tracks' art. Covers appeared only *inside* a playlist.

## What changed

`GET /api/v1/playlists` now sends a `mosaic` of up to four track cover URLs per
playlist.

The list endpoint carries no tracks, so the client had nothing to build a
mosaic from and was passing an empty one; the detail endpoint does carry them,
which is why the detail screen looked right. The web UI has always built its
mosaics in `PlaylistController::mosaics()` — the JSON API was simply never
given the same.

Built in one grouped pass: one query for the pivot rows, one for the items.
A query per playlist would make the app's **first screen** cost grow with the
number of playlists, so there is a test asserting the query count.

## Worth knowing

- Additive. An older client ignores the new key.
- Paired with iOS 0.16.1, which reads it. An older server sends no `mosaic` at
  all, and the app decodes that to empty rather than failing the playlist — so
  the two can be updated in either order.
- Covers are capped at four because the grid is 2×2. One playlist here holds
  1,191 tracks; sending every cover would make the list pay for the library.

## Still wrong

Nothing found here. The web playlists page was verified as already correct at
every layer — controller, component, HTTP response and compiled CSS — before
the cause was traced to the API.

## Tests

PHP · `tests/Feature/Api/PlaylistApiTest.php` 10/10, four new: the list carries
track covers, at most four of them, a playlist whose tracks have no covers
sends an empty mosaic, and the list does not query once per playlist.

Full suite 969/974, 4 skipped. The one failure, `ListPageCostTest`, is
unrelated and reproduces on a clean tree — S-362.
