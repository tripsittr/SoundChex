# 164 — Playlist Porter plugin (import a playlist from the admin)

Phase 2 of playlist porting (S-309 / S-311): the desktop/server face of it. The
porting engine — parsing, matching, playlist creation — is core code (S-310);
this is the admin screen that drives it, shipped as a first-party bundled plugin
rather than a core page, so it also demonstrates a plugin contributing a whole
working screen.

## Added

- **A bundled `soundchex.playlist-porter` plugin** with an **Import Playlist**
  page under Library in the admin. Upload an M3U / CSV / XSPF; it is matched to
  the library and saved as a playlist, and the tracks that couldn't be matched
  are listed with a picker to resolve each to a library item (or leave it out).
- The page is a **custom Blade view**, not a generated Filament form — a plain
  file picker and results panel — showing a plugin page need not be a full
  Filament schema. It drives the shared `PlaylistImportService`, so the admin and
  the mobile app port playlists through exactly the same engine.
- Gated to server admins (the same door the System pages use), since importing
  writes playlists to the account.

## Testing

- `PlaylistPorterPluginTest` (4 pass): the plugin registers its page through the
  admin-page seam; a capped profile can't reach it; importing a file creates a
  playlist of the matched tracks; an unmatched track resolves to a chosen item
  from the page.
- The existing plugin suites (loader, bundled, activity-log) still pass (25).

## Next

Phase 3 adds service connectors with OAuth (S-312); phase 4 the mobile/all-
platform UI (S-313).
