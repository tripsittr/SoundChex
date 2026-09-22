# 163 — Playlist porting: server core + file import

The foundation of playlist porting (S-309, phase 1 / S-310): bring a playlist in
from a file, matched to your library. Every client drives this same server work;
service connectors (Spotify, Apple Music) and the mobile UI build on it.

## Added

- **Import a playlist file** — M3U/M3U8, CSV (incl. Spotify/Exportify exports),
  and XSPF, each parsed by a small pure-PHP parser (no new dependency). A CSV or
  Spotify export keeps its ISRC and Spotify id for a decisive match.
- **Track matching** (`TrackMatcher`) mirrors the duplicate detector's identity
  cascade — ISRC → MusicBrainz recording id → Spotify id → fuzzy artist+title
  (+album, +duration) — so a ported playlist points at the same recordings the
  rest of the app already treats as matches. Matching is routed through the
  ContentGate, so a track a profile may not see is never matched into its
  playlist.
- **`POST /api/v1/playlists/imports`** — upload a file; a short playlist is
  matched in the request and returned complete, a long one is queued
  (`ImportPlaylistJob`, `import` queue) and polled via
  `GET /playlists/imports/{id}`. Unmatched tracks come back as data.
- **Resolve an unmatched track** — `POST /playlists/imports/{id}/resolve`
  attaches a chosen library item to the created playlist and drops it from the
  unmatched list.
- A `playlist_imports` table records each port's source, status, counts,
  unmatched tracks, and the resulting playlist.

## Not yet

Phase 2 wraps this as a desktop/server **plugin** (S-311); phase 3 adds service
**connectors** with OAuth (S-312); phase 4 is the **mobile/all-platform** UI
(S-313). This phase is the shared engine they all use.

## Testing

- `PlaylistImportTest` (6 pass): an M3U imports and matches, an unmatched track is
  listed; a CSV matches by ISRC despite a different title; an XSPF imports; an
  unrecognised file is rejected; an unmatched track resolves to a chosen item; a
  large playlist is queued.
