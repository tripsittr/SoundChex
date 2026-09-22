# 165 — Playlist porting: streaming-service connectors (Spotify)

Phase 3 of playlist porting (S-309 / S-312): pull a playlist straight from a
streaming service, not just a file. The connector turns the service's playlists
into the same normalised tracks the file parsers produce, so matching and
playlist creation are unchanged — a Spotify import just arrives with an ISRC and
a Spotify id, which match the library decisively rather than by fuzzy text.

## Added

- **A `PlaylistSource` connector interface** — one implementation per service,
  each able to say whether it is configured (the operator set app credentials)
  and connected (the user authorised it), hand back an OAuth URL, complete the
  connection, list the user's playlists, and fetch one as ordered tracks. A
  `PlaylistSourceRegistry` lists and resolves them.
- **A Spotify connector** — Authorization Code OAuth (per-user tokens, stored
  encrypted and refreshed transparently), reading `/me/playlists` and a
  playlist's tracks with their ISRC, Spotify id, artist, album and duration.
- **API** under the `auth:sanctum` group:
  - `GET /playlists/sources` — the services, with configured/connected state.
  - `POST /playlists/sources/{source}/authorize` → the consent URL (+ a signed
    state); `…/callback` completes it; `DELETE …/{source}` disconnects.
  - `GET …/{source}/playlists` — the connected user's playlists.
  - `POST …/{source}/import` — import one, matched and saved as a playlist (short
    ones in the request, long ones queued), reusing the S-310 engine.

## Set-up

A connector is dormant until the operator registers the service's app and stores
its client id/secret in settings; Apple Music and YouTube Music slot in behind
the same interface as they are built.

## Testing

- `SpotifyImportTest` (4 pass, HTTP faked): sources report configured-but-not-
  connected; the OAuth callback connects the account; a playlist imports and
  matches by ISRC (the ones the library has); importing without connecting is
  refused. All porting suites pass together (14).
