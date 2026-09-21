# 148 — Search results play, and carry their artist (S-292)

The native app's search was buggy in two ways the owner hit: a song tapped from
the results often would not play, and many rows showed no artist. Both were
server-side, in how `/api/v1/search` builds its response.

## Two bugs, both in the search response

1. **Under-loaded items.** Search gathers items from several groups — titles,
   people, dialogue, book pages. The people, dialogue and page paths loaded a
   *partial* item (`id, title, type` only, no metadata relations, no
   `file_path`). The app serialises each item in full, so:
   - `subtitle` (the artist for a track) was null → **no artist shown**, and
   - `playable` is derived from `file_path`, which was not loaded → **false**, so
     the app treated the track as unplayable.
   Fixed by eager-loading `file_path` and the four metadata relations
   (`musicMetadata` etc.) in the people, dialogue and page searches, matching the
   titles search that was already whole.

2. **Dialogue and page hits were dropped entirely.** The API flattener collected
   items only from rows with `kind === 'item'`. A dialogue hit is `kind === 'cue'`
   and a page hit `kind === 'page'`, each carrying its item under `item` — so
   those never reached the app at all. Now keyed off the item being present, not
   the kind, so a track found by its dialogue or a book found by its text shows
   up (and, with fix 1, is playable).

Together these explain both symptoms: a song surfaced through its artist (the
people group) came back artist-less and unplayable, and dialogue/page matches
either did not appear or could not play.

## Scope

Server-side only — the iOS app needs no change; it consumes the corrected
response. (One unrelated iOS nicety noticed: a non-music search result does
nothing when tapped, since only music plays inline; a video/book tap could open
its detail. Left for a follow-up — not part of this bug.)

## Tests

New `Api/SearchApiTest`: an item found through dialogue comes back playable with
its metadata; a music track found through its artist carries the artist. Search /
media / library suites green (110).
