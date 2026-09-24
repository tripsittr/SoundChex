# 197 — Adding a song twice says so

**Merged** 2026-09-24 · **Issues** S-373

Adding a track a playlist already holds used to move it silently to the end.
It now says so, and lets you choose.

## What changed

The pivot's primary key is `(collection_id, media_item_id)`, so a playlist
**cannot** list the same song twice. `syncWithoutDetaching` therefore did not
add a second row — it rewrote the existing one's `sort_order`, moving the track
to the end of the playlist with no indication anything had happened.

Both add endpoints (`/api/v1/playlists/{id}/items` and the web
`/app/playlists/{id}/items`) now return **409** with `already_present: true`
rather than doing that. Repeat the request with `move_to_end: true` to move it
deliberately.

Because a true duplicate is impossible without a schema change, "move to the
end" is the honest description of what adding again does — so that is what the
clients offer, alongside Cancel.

## Worth knowing

- The web comment claimed "adding never reorders what is already there". It
  did exactly that for a track already on the playlist. Corrected.
- A track that is *not* already there is unaffected: still a 200, still
  appended.
- Additive for callers that do not send `move_to_end` — but a client that
  relied on the old silent-move behaviour now gets a 409 instead. Only the web
  UI and the app call these, and both are updated.

## Still wrong

Real duplicates remain impossible. Allowing them means a surrogate key on the
pivot and rework of reorder and remove, which was considered and deliberately
not done.

## Tests

PHP · `tests/Feature/Api/PlaylistApiTest.php` 13/13, three new: a track already
there is refused with 409 and keeps its position, `move_to_end` moves it
without duplicating, and a track that is not there is still added normally.

Playlist suites together 47/47.
