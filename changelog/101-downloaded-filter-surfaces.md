# 101 — Extend the Downloaded filter to album/artist/playlist (S-116)

*2026-09-18.*

## Done

The "Downloaded" filter (S-115) lived only on the songs list (`/app/music`).
Extended it to the album, artist and playlist pages — the surfaces where you'd
most want to see only what's on the device.

- New `resources/views/media/partials/downloaded-toggle.blade.php` — the filter
  control in one place, included on album, playlist and artist pages.
- The artist page gets one toggle that governs both its "Singles" and "Appears
  on" track lists (the filter script acts on every `li[data-long-press-menu]`
  row on the page).

## Notes

No JS change needed: `downloaded-filter.js` already delegates on `document`,
finds the control by `[data-filter-downloaded]`, and dims non-downloaded rows
reading IndexedDB — so the same proven mechanism now works on three more
surfaces. The filter state is remembered across pages (localStorage). Browse
keeps its own in-form placement. 79 album/artist/playlist/browse tests pass.
