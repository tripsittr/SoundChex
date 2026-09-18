# 096 — Bundled server Step 5: the Server app ships & supervises the runtime (S-151)

*2026-09-18.*

## Done

The **SoundChex Server** desktop app now carries the whole server runtime and
spawns it itself — no system PHP, no Herd, no host plists.

- **Bundled as resources.** `tauri.server.conf.json` packages `runtime/bin/*`
  (php, php-fpm, caddy, ffmpeg, ffprobe, cacert.pem) and `runtime/templates/*`
  (Caddyfile, php-fpm.conf). A pre-build step,
  `scripts/stage-server-runtime.sh <bundle>`, stages them from a built runtime
  bundle; the staged dir is gitignored (not committed).
- **`supervisor.rs` — a Rust supervisor.** One watcher thread owns php-fpm +
  caddy + queue + scheduler, respawns any that crash while running, and kills
  them cleanly on stop. New desktop commands `server_start` / `server_stop` /
  `server_status` resolve the bundled paths via
  `app.path().resource_dir()/runtime/bin`. The old plist-copy `start_service`
  stays as the client app's fallback.

## Verified (macOS)

`tauri build --config src-tauri/tauri.server.conf.json` produced **SoundChex
Server.app** (304 MB). The bundled php/php-fpm/caddy/ffmpeg land at
`Contents/Resources/runtime/bin/` (exactly where the Rust resolves them) and run
from inside the .app (php 8.4.25, caddy 2.11.4). `cargo check` clean.

## Still open

- First-run wiring: `server.html`'s JS should call `server_start` on launch and
  show `server_status`. The Rust side is done and building; this is the UI glue.
- Clean-box verification (no PHP/Herd) belongs with the Step 9 cutover.

## Note

Two ways to run the server, same runtime underneath: the **headless** tarball
(Step 6, web-managed) and this **Server app** (app-managed). The download page
already offers both.
