# 105 — Record where a play came from (S-120)

*2026-09-18.*

## Done

A play stamped the item, user and profile but never the surface it started
from. Now it records a **source**.

- **`source` column** on `media_plays` (nullable, 32 chars), from a fixed set
  (`MediaPlay::SOURCES`: album, artist, playlist, search, home, browse, show,
  queue).
- **Both stream endpoints** (web `MediaCenterController` + API `MediaController`)
  read a `from` query param, validate it against the set, and record it — an
  unknown value records null rather than polluting the column.
- **The web player** sends `?from=<source>` on the stream URL, inferred from the
  page it's on (`player.js` `currentSource()`/`withSource()`). A local
  (downloaded) play doesn't hit the stream endpoint, so it records no source,
  which is correct.

## Tests

Three: a known source is recorded, an unknown one records null, no source
records null. 27 play/listening tests pass.

## Follow-up

The native iOS app records plays via the same API stream endpoint; wiring its
player to send `from` is a small iOS follow-up (the server already accepts it).
