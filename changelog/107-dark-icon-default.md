# 107 — New brand icon, dark as default

*2026-09-18.*

## Done

New SoundChex logo set — the red headphones-and-waveform mark. The **dark** icon
(the mark on a black rounded tile) is now the default app icon everywhere.

- **Desktop (Tauri):** regenerated the whole `src-tauri/icons/` set (icon.png,
  .icns, .ico, every Square*Logo, and the android/ios icon sets) from the dark
  square source via `tauri icon`.
- **Web:** `favicon.ico` (16/32/48/64) and `resources/images/app-icon.png`
  regenerated from the dark tile.
- **Brand sources** kept in `resources/images/brand/` (light/dark × transparent/
  square-bg), so the masters are versioned rather than living loose on disk.

## Notes

Generated from the 512px source (upscaled where a platform needs more, e.g.
iOS 1024) — replace with a larger master later for crisper large sizes. The
in-app and marketing *logos* (header/footer/hero) are unchanged; this is the
launcher/favicon icon only. The website's favicon + app-icon are updated in the
website repo separately.
