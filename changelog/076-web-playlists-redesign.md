# 076 — Spotify-style playlists on web + desktop

**Merged** 2026-09-17 · **Issues** S-149

The web (and desktop, which renders it) playlist pages now look like Spotify /
Apple Music: cover-card grid, big-cover detail header, and inline editing.

## What changed

### Playlists list → cover grid

`media/playlists` was a plain text list; it's now a responsive **cover-card
grid**. Each card shows the playlist's cover — its uploaded image, or a 2×2
**mosaic** of the first four tracks' covers when it has none. A "New playlist"
tile creates one inline (Alpine reveal) without leaving the grid. The mosaics
are built in one grouped pass (`PlaylistController::mosaics()`) — no query per
card.

### Playlist detail → big-cover header + edit

The detail header is now a large cover beside the title block. An **Edit** modal
(Alpine) renames, edits the description, and **uploads a cover** with a live
preview — one multipart `PATCH`. Delete moved into the same modal. The existing
drag-to-reorder track list, Play/Shuffle, and "Download all" are unchanged.

### Controller

`update` now accepts a partial edit (`name` `sometimes`) and a `cover` file,
replacing any previous cover so old files don't linger. `show`/`index` pass the
mosaic cover URLs. Cover column is `collections.artwork_path` (migration in the
API PR, PR #75).

## Worth knowing

- Covers are stored under `storage/app/public/playlist-covers` — needs the
  public storage symlink (already required for item artwork; see S-112).
- Same `Collection::artworkUrl()` the API uses, so a cover set on one client
  shows on all.
- Alpine + `x-cloak` (already styled) drive the reveal and modal — no new JS.

## Still wrong

Nothing new. The mosaic is client-rendered on both platforms; the server does
not composite a single image.

## Tests

PHP — 3 added to `AlbumAndPlaylistTest` (description-only update, cover upload,
cross-account update 404); the file is 29 green. API side covered by
`PlaylistApiTest` (PR #75). Blade views compile (`view:cache`). `npm run build`
clean, service worker stamped.
