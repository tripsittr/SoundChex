# 108 — Client theme customization (web + desktop)

*2026-09-18.* · **Issue** S-157

## Done

The same personalisation the iOS app got: each client can choose its **accent**,
its **light/dark background tone**, and a **system / light / dark** appearance.
It's a property of the device, not the account — stored in `localStorage` and
applied live — so anyone sharing a server keeps their own look.

- **New Appearance panel** on the settings page (`resources/views/media/settings.blade.php`):
  a mode segmented control, ten accent presets plus a custom colour picker, and
  dark/light background pickers, with a "Reset to SoundChex defaults" button.
- **`resources/js/theme.js`** applies a saved theme by setting the existing CSS
  custom properties on `:root` (`--sc-accent`, `--sc-accent-hot`, the
  `--sc-base-*` surface ramp, the `--sc-ink-*` text ramp), derives the surface
  and ink ramps from the chosen background, and flips them on light. Delegated
  event handlers keep it working across Livewire SPA navigation; a
  `prefers-color-scheme` listener re-applies when appearance is "system".
- **No flash of the defaults:** an inline bootstrap in the `<head>` reads the
  same `soundchex.theme` key and sets `--sc-accent` / `--sc-base-900` *before*
  the stylesheet loads.
- Registered `resources/js/theme.js` as a Vite entry so it resolves through the
  manifest.

## Notes

- Desktop (Tauri) rides the same web build, so it gets this for free.
- The theme is per-device by design — it does not sync between a user's phone,
  desktop, and browser. The defaults match the iOS app's
  (`accent #e11d3a`, dark `#08080b`, light `#f7f7f8`).

## Tests

PHP: 603 passing (ProfileSettingsTest gains `the appearance panel is present`).
Build: `vite build` + offline-shell embed + tauri-bridge all run clean; the
service worker was re-stamped.
