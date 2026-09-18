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
