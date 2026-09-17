# 075 — Full playlist editing over the API

**Merged** 2026-09-17 · **Issues** S-149

The native app and desktop can now do real Spotify/Apple-style playlist
editing: rename, describe, drag-reorder, and set a cover — over the token API,
not just the web session.

## What changed

### New playlist endpoints

- `PATCH /api/v1/playlists/{id}` — rename and/or edit the description.
- `PUT /api/v1/playlists/{id}/order` — reorder from a full ordered id list
  (drag-to-reorder persists in one request).
- `POST /api/v1/playlists/{id}/cover` — upload a cover image (≤5 MB), replacing
  any previous one so old files don't linger.

The web controller already had `update`/`reorder`; the API now mirrors them so
every client shares one shape.

### Richer payloads

`index` and `show` now return `artwork_url`; `show` also returns `count` and
`duration_ms` (the total run time). Duration is summed **after** the content
gate, so a capped profile's total reflects only the tracks it can play.

### Cover column

`collections` gains a nullable `artwork_path`, served through the public disk
like item artwork (`Collection::artworkUrl()` mirrors `MediaItem::coverUrl()`).

## Worth knowing

- Migration `2026_09_17_130000_add_artwork_to_collections` is included and has
  been **run on this machine**. Additive, nullable — safe on other installs.
- `reorder` only touches ids already on the playlist (`updateExistingPivot`), so
  a stray id is ignored rather than added — it can't smuggle a track past the
  add gate.
- Ownership is enforced with **404, not 403**, on every playlist route: a
  household should not learn another account's playlist exists from a status
  code.

## Still wrong

Nothing new revealed. Cover images aren't yet mosaicked from track art on the
server — the client draws that fallback when `artwork_url` is null.

## Tests

PHP — `tests/Feature/Api/PlaylistApiTest.php`, 6 tests / 17 assertions: update,
duration+count, reorder, reorder-ignores-stray, cover upload, and cross-account
404. Existing `AlbumAndPlaylistTest` (26) still green.
