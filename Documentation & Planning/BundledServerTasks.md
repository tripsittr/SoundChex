# Bundled server — task breakdown (S-151)

The *what to do next* for [BundledServer.md](BundledServer.md). Each step is
independently verifiable and ships as its own PR, so the current `artisan serve`
setup keeps working until the final cutover. Steps are ordered by dependency.

Legend: `[ ]` not started · `[~]` in progress · `[x]` done.

---

## Step L — Apply the AGPLv3 licence (independent; can happen now)

The licence choice (AGPLv3, S-151) can land before any server work.

- [ ] Add `LICENSE` (the full AGPLv3 text) at the repo root.
- [ ] Set the licence field where declared (`composer.json` `"license"`,
  `package.json`, `src-tauri/Cargo.toml` / `tauri.conf.json`).
- [ ] README: state AGPLv3 and the "app is AGPL, the network service is the paid
  product" model, so contributors and forkers understand the terms up front.
- [ ] Per-file AGPL headers where practical (or a NOTICE + a single header
  policy), and a `THIRD-PARTY-LICENSES.txt` scaffold to fill as bundling lands.
- **Verify:** `LICENSE` present and correct; licence metadata consistent across
  the manifests; README states it. (Owner is separately obtaining formal/legal
  confirmation.)

## Step 0 — Prove the stack by hand (no product changes)

- [ ] Run the existing app on this machine behind **Caddy → php-fpm** manually,
  using a static PHP build, bypassing `artisan serve`. Set a 25 MB upload cap in
  the Caddyfile. Confirm `/app` loads, a stream plays, and a 5 MB cover uploads.
- **Verify:** the app is fully usable through Caddy+php-fpm with `artisan serve`
  stopped; document the exact Caddyfile, php-fpm pool config, and PHP build
  flags that worked. Nothing verifiable downstream until this does.

## Step 1 — Choose and produce the PHP build

- [ ] Settle on a static-PHP source (e.g. static-php-cli) and produce a
  relocatable PHP + php-fpm per platform with the required extension set
  (mbstring, fileinfo, openssl, curl, intl, gmp, pcre, ctype, filter, hash,
  session, tokenizer, pdo_sqlite, sqlite3, gd, pcntl) and `cacert.pem` beside
  the binary.
- **Verify:** `php -m` lists every required extension on each OS; the app's
  feature set works against it (tags read via getid3, HTTPS via curl, SQLite).

## Step 2 — Extension health check (app-side, ships independently)

- [ ] Add a boot/health check that asserts the required extension set and refuses
  with a clear message naming what is missing. Wire it into a `php artisan`
  command and the Filament health surface.
- **Verify:** removing one extension from a test build makes the check fail with
  the extension named — teeth-checked.

## Step 3 — Re-test the single-threaded assumptions

- [ ] Audit and re-test S-123, S-87, S-93 and everything that assumes a
  single-threaded / loopback server (NetworkAddresses, ProbeNetworkJob, the
  connection watcher `window.soundchexOffline`). Fix what a concurrent php-fpm
  breaks.
- **Verify:** the network-probe and offline-detection tests pass against a
  running Caddy+php-fpm, not `artisan serve`.

## Step 4 — Supervision, cross-platform

- [ ] Replace the three Herd-pathed plists with a supervisor that starts Caddy +
  php-fpm + queue + scheduler from **bundled binaries by relative path**:
  launchd (macOS), systemd units (Linux — currently unwritten), a real Windows
  service (not NSSM-in-docs). Reconcile with `HostServices.php` and the Tauri
  service-control UI.
- **Verify:** on each OS the services start at boot, restart on crash, and stop
  cleanly; the Services admin page reflects real state.

## Step 5 — Bundle into the desktop app

- [ ] Add `php`, `php-fpm`, `caddy` as Tauri sidecars (`externalBin`/`resources`)
  and have the desktop app **spawn and supervise** them (new capability — today
  it only `launchctl load`s host plists). Include `THIRD-PARTY-LICENSES.txt` in
  the bundle.
- **Verify:** installing the built desktop app on a machine with **no PHP / no
  Herd** yields a working server; uninstalling removes the services.

## Step 6 — Standalone headless installer

- [ ] A per-OS installer/script that drops the same runtime + Caddyfile and
  registers the service, no GUI. Shares artifacts with Step 5.
- **Verify:** a headless box (no desktop app) serves `/app` over HTTPS after the
  installer runs.

## Step 7 — TLS reconciliation

- [ ] Decide and implement Caddy's TLS story vs the Tailscale cert / a real
  domain / Caddy's local CA. Update `RemoteAccess.md` and `DirectRemoteAccess.md`
  (which currently assume `tailscale serve` → plaintext 8000).
- **Verify:** remote access over HTTPS works through Caddy; the docs match.

## Step 8 — Bundle full ffmpeg (AGPLv3 makes this trivial)

Under AGPLv3, GPL ffmpeg is compatible, so **bundle the full build** — no
LGPL/OpenH264/VP9 work, and `MediaTranscoder` keeps its current code path
(`h264_videotoolbox` when present, `libx264` fallback, `libmp3lame`, native
`aac`) unchanged.

- [ ] Produce/obtain a **full static ffmpeg** (with `--enable-gpl`, libx264,
  libmp3lame) per platform and bundle it alongside the runtime; point
  `FFMPEG_PATH`/`FFPROBE_PATH` at the bundled binary by relative path.
- **Verify:** a software H.264 transcode (no hardware encoder) produces a
  browser-playable MP4 via `libx264`; music → mp3 works; `ffprobe` detection
  works; `THIRD-PARTY-LICENSES.txt` lists ffmpeg + its GPL notice.

## Step 9 — Migration + cutover + docs

- [ ] On upgrade, remove the old Herd-pathed plists and install the new service
  without leaving a second server on 8000. Rewrite README / BuildingOnEachPlatform
  / SettingUpOnWindows to the "install the product, done" story.
- **Verify:** an existing install upgrades with no orphaned `artisan serve`; a
  fresh install needs nothing but the product.
