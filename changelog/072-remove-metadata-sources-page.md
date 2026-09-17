# 072 — Remove the redundant Metadata Sources page

**Merged** 2026-09-17 · **Issues** S-148

The standalone **Metadata Sources** admin page was removed. Its provider keys
(TMDB, Spotify, Genius, Musixmatch, …) are configured on the **Integrations**
page now, which already renders and saves them — so the separate page under
Settings only duplicated it.

## What changed

`app/Filament/Pages/MetadataSettings.php` is gone. The Integrations page's
`metadataRows()` was already the real form; the doc comments there, which still
described metadata sources as keeping their own page, were corrected.

## Worth knowing

- Nothing else referenced `MetadataSettings`; the app boots clean without it.
- No data change — the keys live in the settings table either way.
