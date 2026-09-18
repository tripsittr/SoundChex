# Bundled server — task breakdown (S-151)

The *what to do next* for [BundledServer.md](BundledServer.md). Each step is
independently verifiable and ships as its own PR, so the current `artisan serve`
setup keeps working until the final cutover. Steps are ordered by dependency.

Legend: `[ ]` not started · `[~]` in progress · `[x]` done.

---

## Step L — Apply the AGPLv3 licence — [x] DONE

Audited 18 Sep 2026 — complete across every repo.

- [x] `LICENSE` (full AGPLv3) present at the root of **all** repos: App, iOS,
  Website, TV, Roku, Android.
- [x] Licence field set: `composer.json`, `package.json`, `src-tauri/Cargo.toml`
  all declare `AGPL-3.0-or-later`.
- [x] README states AGPLv3 and the "app is AGPL, the network is the paid
  product" model.
- [x] Per-file SPDX headers: 225 files in the app repo, all 46 iOS Swift files.
  The server bundle ships `server/THIRD-PARTY-LICENSES.txt` (where third-party
  binaries actually live). (Owner separately obtaining formal/legal confirmation.)

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

## Step 1 — Choose and produce the PHP build — [x] DONE (all 5 targets build)

- [x] **macOS (arm64) built and verified** with static-php-cli 2.8.6, PHP 8.4.25.
  Both `php` and `php-fpm` (fpm-fcgi SAPI) produced; all 27 extensions present;
  opcache statically linked; `cacert.pem` placed beside the binary. Verified
  against the real app: `artisan --version` boots Laravel 13.15.0,
  `Schema::getColumnListing('users')` returns real columns (proves the sqlite
  fix), HTTPS via curl returns 200, and the app runs end-to-end through
  **Caddy → our php-fpm** (`/`→302, `/login`→200, `/app`→302 auth). Packaged as
  `soundchex-server-macos-aarch64.tar.gz` (62 MB) via
  `server/scripts/package-runtime.sh`.
- [x] **Linux x86_64 (~61 MB), Linux aarch64 (~60 MB), Windows x86_64 (~39 MB)
  all build in CI** via `.github/workflows/build-server.yml` (fetches the
  official `spc` binary per OS — spc can't cross-compile). macOS x86_64 uses the
  same proven path and only waits on scarce Intel-mac runners.
- **CI gotchas found & fixed (all in the workflow):** the marketplace action
  `crazywhalecc/static-php-cli-action` does not exist (fetch `spc` directly);
  sqlite must be pinned to source *after* a prebuilt download (explicit
  `spc download sqlite`); Windows has **no php-fpm/cgi SAPI** (build `cli`, own
  `build-windows` job in pwsh); Windows needs the **MSVC dev env** + pinning to
  **windows-2022** (nightly spc rejects VS 18 on windows-latest); **gmp** can't
  compile on Windows and **pcntl** is POSIX-only (dropped from `WIN_EXTENSIONS`);
  Git-bash has no `zip` (fall back to `Compress-Archive`); pass **`GITHUB_TOKEN`**
  so spc's API calls don't hit the 60/hr rate limit; retry doctor/download for
  transient upstream 500s.
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

## Step 2 — Extension health check — [x] DONE

- [x] `App\Services\RuntimeHealth` — single source of truth for the required +
  recommended extension sets (each with *why*), kept in step with `composer.lock`
  and the bundled build flags.
- [x] `php artisan server:check-extensions` — asserts the required set, names
  what's missing and what it breaks, exits non-zero (a supervisor can refuse to
  start). Recommended-but-absent (pcntl/gmp/opcache/sodium) are warnings.
- [x] Wired into the Filament **Services** page ("PHP runtime" section) and
  scheduled daily (`--quiet-ok`).
- **Verify:** ✅ tests simulate a missing `exif`/`curl` — service and command
  both fail *and name it*; a missing recommended extension does not fail.

## Step 3 — Re-test the single-threaded assumptions — [x] DONE

Audited every place that assumed the single-threaded/loopback `artisan serve`.
The picture was better than feared, with **one real bug**:

- **SQLite:** already concurrency-ready — `journal_mode=WAL` (simultaneous
  readers + writers) and `busy_timeout=120000` are configured in
  `config/database.php`. WAL is exactly what concurrent php-fpm workers need.
- **Sessions:** `SESSION_DRIVER=database` — no file-session lock contention.
- **Network state:** `NetworkAddresses` stores probe results in the **cache**
  (atomic), not a file. `ProbeNetworkJob`/`network:probe` are out-of-band (a
  queue job) — the reason they exist is `artisan serve`'s single-threadedness,
  and they work fine (better) under php-fpm. The connection watcher is pure
  client-side JS, origin-agnostic.
- **The bug — `EnvironmentFile::set()`:** a bare `file_get_contents` +
  `file_put_contents` read-modify-write with no lock. Under concurrent php-fpm,
  a `ServerSettings` save and the scheduled `server:detect-address` could
  interleave and clobber a key or tear the file. **Fixed:** exclusive `flock`
  across the whole read-modify-write + atomic temp-file `rename`. New
  `EnvironmentFileTest` covers update/append/atomicity.
- **Verify:** ✅ 21 network/env/session/health tests pass; the one unsafe write
  is now atomic and covered.

## Step 4 — Supervision, cross-platform — [~] macOS + Linux verified; Windows authored

New home: `server/supervisor/` (launchd / systemd / windows templates) +
`server/scripts/install-services.sh` + `uninstall-services.sh`. The topology is
now **four** supervised processes — Caddy, php-fpm, queue, scheduler — each run
from **bundled binaries by relative path** (no Herd), placeholders filled at
install time.

- [x] **macOS (launchd) — verified natively on this machine.** Installed
  test-labelled fpm+caddy services from a locally-built bundle; Caddy (:8400) →
  php-fpm (9101) served **HTTP 200 `sapi=fpm-fcgi`**; killing php-fpm had launchd
  **restart it** (new pid) with the stack still serving 200. Live services were
  never touched; test services cleaned up.
- [x] **Linux (systemd) — verified via Docker.** All four unit templates render
  with bundled paths and no leftover placeholders; the fpm+caddy the units
  describe boot and serve **HTTP 200 `sapi=fpm-fcgi` upload=25M** in a Debian
  container (also runs on Ubuntu/Alpine/Fedora — the static musl build is
  distro-portable).
- [~] **Windows — authored, unverified from this Mac.** `install-services.ps1`
  registers the queue + scheduler as real services (bundled `php.exe`, restart-
  on-crash via `sc.exe failure`) and installs Caddy **if** a `php-cgi.exe` is
  present. It is not yet: Windows has no php-fpm SAPI, so the HTTP front needs a
  `php-cgi` build added to the Windows bundle (a Step-1/4 follow-up). The script
  says so loudly. **To verify on the owner's Windows Server.**
- **Gotcha fixed:** the runtime `php-fpm.conf` had no `user`/`group`, so php-fpm
  refuses to start when a system service runs its master as root. Added
  `user`/`group` (filled by the installer; ignored harmlessly when unprivileged).
- **Still to do:** reconcile `HostServices.php` (it still copies the old
  Herd-pathed plists from `Documentation & Planning/`) to these bundled
  templates — deferred to **Step 5**, where the bundle's install path is concrete.
- **Verify:** ✅ macOS + Linux start and restart-on-crash proven; Windows pending
  real hardware + a php-cgi bundle.

## Step 5 — Bundle into the desktop app — [x] macOS built & verified

- [x] The **Server app** (`net.soundchex.server`) carries the runtime as bundled
  **resources** (`tauri.server.conf.json` → `runtime/bin/*` + `runtime/templates/*`),
  staged before build by `scripts/stage-server-runtime.sh` (gitignored). ffmpeg
  is included, so `THIRD-PARTY-LICENSES` is covered.
- [x] `src-tauri/src/supervisor.rs` — a Rust supervisor: one watcher thread owns
  php-fpm + caddy + queue + scheduler, respawns any that crash while running, and
  kills them cleanly on stop. Commands `server_start` / `server_stop` /
  `server_status` (desktop-only) resolve the bundled paths via
  `app.path().resource_dir()/runtime/bin`. The old `start_service` (plist copy)
  stays as the client app's fallback.
- **Verified on macOS:** `tauri build --config tauri.server.conf.json` produced
  **SoundChex Server.app** (304 MB); the bundled php/php-fpm/caddy/ffmpeg land at
  `Contents/Resources/runtime/bin/` — exactly where the Rust resolves them — and
  run from inside the .app (php 8.4.25, caddy 2.11.4). `cargo check` clean.
- **Still to verify on a clean box (real hardware):** installing the built app on
  a machine with **no PHP / no Herd** yields a working server end to end, and the
  server:detect-address / migrate flow runs from the app's first launch. The
  pieces are proven; the first-run wiring in `server.html`'s JS calling
  `server_start` is the remaining integration.

## Step 6 — Standalone headless installer

- [ ] A per-OS installer/script that drops the same runtime + Caddyfile and
  registers the service, no GUI. Shares artifacts with Step 5.
- **Verify:** a headless box (no desktop app) serves `/app` over HTTPS after the
  installer runs.

## Step 7 — TLS reconciliation — [x] DONE

Three TLS modes, and the bundled `Caddyfile` supports all three:

1. **Behind a TLS proxy (default).** `tailscale serve` / SCNet / a reverse proxy
   terminates HTTPS and forwards to Caddy's plaintext listener — the *same* shape
   as today, Caddy just replacing `artisan serve`. The catch: Caddy must **trust**
   the proxy to honor its `X-Forwarded-Proto`, which Laravel's `trustProxies` (on,
   `at: '*'`) reads to build `https://` URLs. Added
   `trusted_proxies static private_ranges 100.64.0.0/10` (LAN + Tailscale CGNAT)
   to the bundled Caddyfile.
2. **Direct with a real domain.** Remove `auto_https off`, set the site label to
   the domain — Caddy provisions Let's Encrypt automatically.
3. **Direct on a LAN, no domain.** `tls internal` serves Caddy's local CA cert.

- **Gotcha found & fixed:** without `trusted_proxies`, Caddy's `php_fastcgi`
  passes its *own* (plaintext) scheme to PHP, so `X-Forwarded-Proto: https` from
  the proxy was ignored and the app would generate `http://` URLs. With it,
  verified in Docker: a request carrying `X-Forwarded-Proto: https` reaches PHP
  as `https` (HTTP 200).
- **Verify:** ✅ forwarded-TLS path proven; `RemoteAccess.md` updated with the
  bundled-server note.

## Step 8 — Bundle full ffmpeg — [x] DONE

- [x] The packaging script (`package-runtime.sh`) now fetches a **full static GPL
  ffmpeg + ffprobe** per platform (`--enable-gpl`, libx264, libmp3lame) and drops
  them in the bundle's `bin/`: macOS from ffmpeg.martin-riedl.de (native
  per-arch), Linux/Windows from BtbN/FFmpeg-Builds. `SKIP_FFMPEG=1` opts out.
  The script asserts the GPL/x264/mp3lame config on POSIX.
- [x] `config/transcode.php` auto-detects a bundled ffmpeg **beside the PHP
  binary** (the cacert convention: `dirname(PHP_BINARY)/ffmpeg`), so the bundled
  runtime transcodes with zero config. An explicit `FFMPEG_PATH`/`FFPROBE_PATH`
  still wins; else it falls back to PATH.
- **Verify:** ✅ built a macOS bundle with ffmpeg (63 MB each binary); the bundled
  ffmpeg does a real **libx264 H.264 encode** to a valid MP4 (ffprobe reads
  `h264`) and an **mp3 encode** via libmp3lame; the app config resolves to the
  bundled binary under the bundled php; env-override + PATH-fallback tested.
  `THIRD-PARTY-LICENSES.txt` now carries the ffmpeg GPL notice (+ libx264 GPL,
  libmp3lame LGPL, and the H.264-patent note).
- **Note:** ffmpeg adds ~120 MB to a bundle. Acceptable for a media server;
  `SKIP_FFMPEG=1` builds a lean runtime for hosts that already have ffmpeg.

## Step 9 — Migration + cutover + docs — [x] DONE (this machine cut over live)

The build/dev machine (`el-laptop`) was **migrated off Herd to the bundled
runtime**, live:

- [x] Unloaded the old Herd `com.soundchex.serve` (`artisan serve` on :8000) and
  installed the bundled **Caddy + php-fpm + queue + scheduler** via
  `server/scripts/install-services.sh` — all four now run bundled binaries by
  relative path from `src-tauri/runtime/`. No orphaned `artisan serve`; :8000 is
  served only by bundled Caddy → php-fpm (9100). `/login` → 200.
- [x] **DB untouched** (same `database/database.sqlite`, 27 MB; verified the
  bundled php and Herd php read identical data — `users=2` both). A safety backup
  was taken first, and the old plists archived, for rollback.
- [x] **Self-healing confirmed live:** killing the bundled php-fpm had launchd
  restart it (new pid), app still serving 200.
- [x] **Fixed a stale APP_URL** (`macbookair…` — a renamed-away device) via
  `server:detect-address`, then set it to the app's new Tailscale HTTPS front.
- [x] **Remote access (Option 2):** the app got its own Tailscale front —
  `tailscale serve --https 8443 http://127.0.0.1:8000` →
  `https://el-laptop.tail7e590c.ts.net:8443` (tailnet-only, TLS). The **website**
  stays on Funnel :443 → :8100 (public), which is the correct split: the app is
  private to the tailnet, the marketing site is public.

- **Gotcha found & fixed (live):** the bundled `Caddyfile` template's
  `root * {$SOUNDCHEX_ROOT}` and log path were **unquoted**, so an install path
  with spaces ("SoundChex App") broke Caddy's parser. Now quoted in the template
  (fixes both the installer and the Rust supervisor, which share it).
- **Still to confirm (needs a real device):** the `:8443` Tailscale HTTPS front
  returns `000` to *local* curl — the known loopback-through-own-tailnet quirk
  (see the Tailscale-Funnel memory: the phone reaches it when local curl can't).
  Verify from a phone on the tailnet.
- **Docs:** `RemoteAccess.md` carries the bundled-server + TLS note (Step 7);
  README / BuildingOnEachPlatform rewrite to "install the product, done" is a
  remaining doc task.
