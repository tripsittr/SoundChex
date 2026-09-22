# 167 — Playlist porting is now a fully self-contained plugin

Porting was built as core app code with a thin plugin over it (S-310/S-311). Now
that the plugin platform can carry a whole vertical (S-314), the entire engine
has moved *into* the Playlist Porter plugin (S-315): disabling the plugin removes
porting completely — its endpoints, its table, its services all go with it.

## Moved into the plugin

Out of core `app/` and into `plugins/bundled/playlist-porter/`, under the
`SoundChex\PlaylistPorter\` namespace:

- The parsers, `TrackMatcher`, `PlaylistImportService` (→ `src/Services/…`).
- The Spotify source and the source registry (→ `src/Services/Sources/…`).
- `ImportPlaylistJob`, the `PlaylistImport` model, and both API controllers.
- The `playlist_imports` migration (→ the plugin's own `database/migrations/`).
- The `/playlists/imports` and `/playlists/sources` API routes (→ the plugin's
  `routes/api.php`).

The plugin registers them through the new seams: `Registry::migrations(...)` for
its table and `Registry::routes(..., prefix: 'api/v1', middleware: ['auth:sanctum'])`
for its endpoints — so the URLs and route names are byte-for-byte what core once
declared, but they are the plugin's now and are gone when it is disabled.

## Unchanged

Every route URL and name is identical, so no client changes. The engine still
references core services it depends on (the library, the content gate, settings)
by their `App\` namespace — those stay in core.

## Testing

- All porting suites pass unchanged (18): file import, Spotify import, the plugin
  page, and the self-contained seams.
- The other plugin suites (loader, bundled, activity-log) still pass (25).
- `route:list` confirms the endpoints resolve to the plugin's controllers at the
  same URLs, ahead of the `/playlists/{collection}` catch-all.
