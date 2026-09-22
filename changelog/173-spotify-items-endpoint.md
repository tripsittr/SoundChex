# 173 — Spotify playlists read from `/items`

**Merged** 2026-09-22 · **Issues** S-323

Importing a Spotify playlist failed with `403 Forbidden`. The connector was
reading an endpoint Spotify no longer serves to apps in development mode.

## What changed

### The test that should have caught it

`SpotifyResponseShapeTest` pins the shape of Spotify's playlist responses. The
plugin's existing `SpotifyImportTest` faked `/playlists/{id}/tracks` with each
row's payload under `track`, so it passed for as long as real imports were
broken — the fake had drifted from the API it stood in for.

The fix itself is in `soundchex-playlist-porter` v1.0.5: read
`/playlists/{id}/items`, whose rows carry `item` rather than `track` and whose
length is `items.total` rather than `tracks.total`. Both renames fail silently
— zero tracks imported, blank counts in the picker — which is why they are
covered rather than merely fixed.

## Worth knowing

- No migration, no data rewritten.
- **Spotify's development mode only lets an app read playlists the connected
  account created.** Reading anyone else's needs an extended quota, which since
  May 2025 is an enterprise contract rather than something an individual
  developer can request. The plugin now says this plainly and greys those
  playlists out, but it is a limit of the Spotify app, not of SoundChex.
- Verified against the live API: a 530-track playlist imported across 6 pages,
  411 tracks matched. The test import and its playlist were removed afterwards.

## Still wrong

- **Plugin routes are not available in the app's test environment.**
  `PluginServiceProvider::boot()` registers them during app boot, before
  `RefreshDatabase` has created the tables the loader gates on, and `boot()` is
  idempotent — so a test cannot boot a plugin afterwards. These tests therefore
  drive the connector directly instead of its HTTP endpoints, and the plugin's
  own API tests still cannot run standalone. Fixing that is an app-boot change,
  not part of this fix.
- The two Spotify credential paths remain unconsolidated (core Integrations'
  `spotify_client_secret` versus the plugin's `spotify.client_id`/`secret`).
