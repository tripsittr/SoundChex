# 099 — Fix the hanging DMG build (S-74)

*2026-09-18.*

## Fixed

`npm run build:server` (and `tauri:build`) **hung at the DMG step** with no
error — the app compiled, `.app` bundled, then `bundle_dmg.sh` stalled and no
`.dmg` appeared.

Root cause: Tauri's `bundle_dmg.sh` runs **AppleScript** to position icons and
style the Finder window of the mounted DMG. With no interactive GUI session
(a background build, an automation context, CI), that AppleScript blocks forever
waiting on a Finder that will never answer.

Fix: run the bundle scripts under **`CI=true`** (baked into `tauri:build` and
`build:server` in package.json). Tauri then skips the Finder styling and
produces a plain but valid `.dmg`.

## Verified

`CI=true tauri build --bundles dmg` → **`Finished 1 bundle`**, a 2.8 MB
`SoundChex_0.1.0_aarch64.dmg` produced. Without it, the same build stalls at
"Running bundle_dmg.sh" and yields no dmg. README documents the reason.

## Note

The `.dmg` target is macOS-only, so the shell-style `CI=true` prefix only runs on
macOS/Linux and needs no cross-env shim.
