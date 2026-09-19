# 103 — A back arrow in the media UI (S-52)

*2026-09-18.*

## Done

The Tauri desktop window and the phone web-app have no browser chrome, so
getting out of a drilled-into page (an album, an artist, a playlist, an item)
meant navigating all the way round. Added a **back arrow** to the media header.

- Shown on the pages you drill into; hidden on the top-level pages (home, the
  Watch/Music/Books tabs, search) where there is nothing above to go back to.
- Uses `window.history.back()` against the SPA history Livewire already
  maintains — so it walks back through your actual path, not a fixed parent.
- Placed before the logo in the header, within the nav's existing Alpine scope.

## Cross-platform check

Confirmed S-52 is web/Tauri-only. The **native iOS app** already has correct
back navigation: detail views are pushed with `NavigationLink` inside a
`NavigationStack` (automatic system back button), and every `fullScreenCover`
(player, search, settings, profile picker) has its own dismiss — chevron-down or
a "Done" button. **TV / Roku / Android** are scaffolds with no UI code yet.

## Tests

Two feature tests: a detail (album) page shows `aria-label="Back"`; the home
page does not. 89 media tests pass.
