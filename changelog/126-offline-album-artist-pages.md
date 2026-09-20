# 126 — Album and artist detail pages rebuild offline (S-27)

Six library list screens rebuilt from the device mirror when the server was
away; the single album and artist *detail* pages did not. They were pure server
round-trips — ~900ms each over the relay online, and nothing at all offline,
even though the offline album and artist *lists* linked straight to them.

## What this adds

- **`/app/album?artist=…&album=…`** rebuilds one album's tracks from the mirror,
  in disc/track order, as a playable list.
- **`/app/artist?name=…`** rebuilds one artist: their albums as a grid (each
  linking to the offline album page above), then any loose singles as a track
  list.

Both are new handlers in the offline shell's `SCREENS` table, so they are picked
up by the same dispatch as every other offline screen — no separate wiring.

## How it stays consistent

- Two pure query helpers do the filtering: `albumTracks()` and `artistAlbums()`.
  They match artist and album the **same normalised way** `albums()` groups them
  (case- and accent-insensitive), so the detail page shows exactly the group the
  list linked to — "Nilüfer"/"Nilufer" or a tag/link case difference resolves to
  one album rather than an empty page.
- The album tile markup is now one shared `albumCard()`, used by the album list
  and the artist page, so they cannot draw an album two different ways or link it
  two different places.

## Scope

Web/desktop offline shell. On mobile the native iOS app already builds these
from its own catalogue mirror, so this closes the web/desktop side S-27
described.

## Tests

- `library-query.test.js` (vitest) — `albumTracks` orders and matches
  case/accent-insensitively and does not mix same-titled albums across artists;
  `artistAlbums` groups albums, separates singles, and returns empty for an
  unknown artist.
- `offline-shell.spec.js` — the single album and single artist pages rebuild
  offline (verified on Chromium; the WebKit project's pre-existing
  service-worker timeout is tracked separately as #278).
- Full suites: 103 JS, 732 PHP passed.
