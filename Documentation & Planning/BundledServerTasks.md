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

## Step 0 — Prove the stack by hand (no product changes) — [x] DONE (18 Sep 2026)

- [x] Ran the app behind **Caddy → php-fpm** manually, bypassing `artisan serve`,
  with a 25 MB upload cap. `/` → 302 `/login`, `/login` → 200 (Laravel HTML +
  CSRF), `/app` → 302 to login (auth middleware), `/build/manifest.json` served
  **directly by Caddy** as static (not through PHP). A 5 MB POST body was
  accepted (419 at CSRF, i.e. past the size gate — `artisan serve` dies at 2 MB);
  a 30 MB body was rejected 413 by Caddy's cap. Effective PHP config through the
  stack: `sapi=fpm-fcgi`, `upload_max_filesize=25M`, `post_max_size=26M`.
- **Verify:** ✅ The stack is self-contained (its own FPM on 127.0.0.1:9100) and
  works with the app's `artisan serve` irrelevant to it. Recipe below.

### The working recipe (macOS, PoC)

**PHP build.** Used Herd's `php84-fpm` (`~/Library/Application Support/Herd/bin/`)
— which is itself **static-php-builder output** (`--enable-static=yes`,
`PHP_BUILD_PROVIDER=Laravel Herd`, NTS), i.e. the same static-PHP-CLI toolchain
Step 1 names. `php-fpm -v` reports `fpm-fcgi`; all required extensions present
(mbstring, fileinfo, openssl, curl, intl, gmp, ctype, filter, hash, session,
tokenizer, pdo_sqlite, sqlite3, gd, pcntl). This is why the PoC is meaningful and
not just a stand-in: it proves a static-PHP-CLI `php-fpm` runs the app correctly.

**php-fpm pool** (`php-fpm.conf` — TCP, not a unix socket: portable across
macOS/Linux/Windows, and avoids the 104-char `sun_path` limit):

```ini
[global]
pid = <run-dir>/php-fpm.pid
error_log = <run-dir>/php-fpm.log
daemonize = no
[soundchex]
listen = 127.0.0.1:9100
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 26M
php_admin_value[memory_limit] = 512M
clear_env = no
catch_workers_output = yes
```

Start: `php84-fpm --fpm-config php-fpm.conf --nodaemonize`.

**Caddyfile** (Caddy owns the port + static files; reverse-proxies PHP to FPM;
sets the upload cap that is now *ours*):

```caddyfile
{
	admin localhost:2999   # a live admin endpoint; `admin off` makes `caddy start` hang
	auto_https off         # PoC only — TLS is Step 7
}
:8200 {
	root * "<app>/public"
	encode gzip
	request_body { max_size 25MB }
	php_fastcgi 127.0.0.1:9100
	file_server
	log { output file <run-dir>/caddy-access.log }
}
```

Start: `caddy run --config Caddyfile --adapter caddyfile` (**`caddy run`**, not
`caddy start` — the latter blocks waiting on a readiness signal and hangs).

**Gotchas found:** (1) `caddy start` hangs — use `caddy run` backgrounded.
(2) A unix socket under a deep path silently truncates at 104 chars and Caddy
can't find it — TCP sidesteps it and is the portable choice anyway. (3) Caddy's
`request_body { max_size }` is a hard gate *before* PHP, so it (not just
`post_max_size`) is what turns a 30 MB body into a 413.

## Step 1 — Choose and produce the PHP build — [~] macOS done; Linux/Windows in CI

- [x] **macOS (arm64) built and verified** with static-php-cli 2.8.6, PHP 8.4.25.
  Both `php` and `php-fpm` (fpm-fcgi SAPI) produced; all 27 extensions present;
  opcache statically linked; `cacert.pem` placed beside the binary. Verified
  against the real app: `artisan --version` boots Laravel 13.15.0,
  `Schema::getColumnListing('users')` returns real columns (proves the sqlite
  fix), HTTPS via curl returns 200, and the app runs end-to-end through
  **Caddy → our php-fpm** (`/`→302, `/login`→200, `/app`→302 auth). Packaged as
  `soundchex-server-macos-aarch64.tar.gz` (62 MB) via
  `server/scripts/package-runtime.sh`.
- [ ] **Linux (x86_64, aarch64) + Windows (x86_64):** static-php-cli cannot
  cross-compile, so these build on their own runners via
  `.github/workflows/build-server.yml` (crazywhalecc/static-php-cli-action).
- **Verify:** `php -m` lists every required extension on each OS; the app's
  feature set works against it (tags read via getid3, HTTPS via curl, SQLite).
  ✅ done for macOS.

### Gotcha found: sqlite column metadata

The default static-php-cli **prebuilt** sqlite library is compiled *without*
`SQLITE_ENABLE_COLUMN_METADATA`, which spc's own sanity check rejects (and which
Laravel schema introspection needs — `Schema::getColumnListing`,
`sqlite3_column_table_name`). Fix: don't `download` sqlite with
`--prefer-pre-built`; force it to build **from source**. Concretely, if the
prebuilt lib was already fetched: delete `buildroot/lib/libsqlite3.*` +
`buildroot/include/sqlite3*.h`, remove the `sqlite-<os>-<arch>--default`
(prebuilt, `lock_as: 2`) entry from `downloads/.lock.json` leaving the `sqlite`
(source, `lock_as: 1`) entry, `spc extract sqlite`, then `spc build … -r`. The
CI action builds sqlite from source by default, so this only bites local builds
that pass `--prefer-pre-built`.

### The Windows php-fpm problem

PHP ships **no php-fpm SAPI on Windows**. The Windows server story must use
`php-cgi` behind Caddy's `php_fastcgi` (Caddy can spawn/manage a php-cgi pool)
instead of php-fpm. Flagged in `build-server.yml`; the packaging + supervision
for Windows (Step 4) has to account for this — it is not a drop-in of the POSIX
layout.

### The definitive extension set (audited 18 Sep 2026)

The plan's original list was **incomplete** — audited against `composer.lock`
(transitive `ext-*` requires), app code, and key package needs:

- **`composer.lock` transitive requires:** ctype, dom, fileinfo, filter, hash,
  iconv, intl, json, libxml, mbstring, openssl, pcre, phar, session, tokenizer,
  xml, xmlreader, xmlwriter, zip. *(dom, iconv, json, libxml, phar, xml,
  xmlreader, xmlwriter, zip were missing from the plan's list.)*
- **App-required beyond that:** pdo_sqlite + sqlite3 (the DB), curl (HTTP client
  / `NetworkAddresses` / `ArtistProfiles`), pcntl (Laravel's queue Worker calls
  `pcntl_signal`/`pcntl_alarm` — required for a real queue worker), opcache
  (perf), zlib (getid3, zip).
- **getid3 (music tag reading) benefits from:** exif (embedded artwork), iconv,
  mbstring, xml, zlib — so **add exif**.
- **Keep from the plan, low-cost:** gd (getid3 image paths; no PHP image lib in
  the lockfile, so not strictly required, but cheap and expected), gmp
  (ramsey/uuid *suggests* it for perf — not required; include if free).

**Final `--with-extensions` for static-php-cli (macOS/Linux/Windows):**

```
bcmath,ctype,curl,dom,exif,fileinfo,filter,gd,gmp,iconv,intl,mbstring,
opcache,openssl,pcntl,pdo,pdo_sqlite,phar,session,sodium,sqlite3,tokenizer,
xml,xmlreader,xmlwriter,zip,zlib
```

(pcre, hash, json, libxml, spl, standard are always-on core in static-php-cli;
sodium/bcmath added for Laravel crypto/uuid completeness.) The `cacert.pem` must
sit beside the built binary — `TransferReceiver.php:1275` locates it as
`dirname(PHP_BINARY) . '/cacert.pem'`.

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
