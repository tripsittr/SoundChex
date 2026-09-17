# A bundled, production server — plug-and-play self-hosting

Replace `php artisan serve` with a real application server (**PHP-FPM behind
Caddy**), and ship the whole runtime — PHP, php-fpm, Caddy — inside the product
so a self-hoster installs one thing and has a working server. No Herd, no
system PHP, no three terminals.

**This is the authoritative plan (S-151).** The tickable, per-step breakdown is
in [BundledServerTasks.md](BundledServerTasks.md); this document is the *why*
and the shape.

Decisions already made (17 Sep 2026):

- **Runtime: PHP-FPM + Caddy.** Caddy terminates HTTP(S) and reverse-proxies to
  php-fpm over a local socket. Chosen over FrankenPHP/Octane for being the
  conventional, well-understood split — the trade is more moving parts for
  fewer surprises. (FrankenPHP would have been one binary; revisit if the
  three-part supervision proves fiddly.)
- **Ships two ways:** (1) bundled **inside the desktop app** (installing the app
  installs the server), and (2) a **standalone headless installer** for a
  machine with no GUI — a NAS or a home-server box.

---

## Why replace `artisan serve`

`php artisan serve` is Laravel's *development* server. The whole codebase knows
it: it is single-threaded, dies with its terminal, and has conservative PHP
defaults (the 2 MB `upload_max_filesize` that broke playlist cover uploads,
S-149 follow-up). Several fixes — S-123, S-87, S-93 — exist only to work around
its single-threadedness and loopback binding. It is not a server to hand to
other people.

Today it is worse than "dev server": it depends on **Herd's `php84`**,
hardcoded by absolute path in three launchd plists. There is no bundled PHP and
no download step, so "plug and play" is not true even for the person who wrote
it — a fresh machine needs Herd, PHP 8.4+, the right extensions, Composer, Node,
and a hand-configured CA bundle before the server runs at all.

The goal: **installing the product installs a working, production-grade server**,
with the runtime version and extensions under our control, not the host's.

## What "plug and play" legally allows us to bundle

Everything in the PHP-FPM + Caddy stack is permissively licensed and
redistributable in a commercial product. The one obligation is attribution: a
`THIRD-PARTY-LICENSES.txt` in every installer and app bundle.

| Component | License | Redistribute in a paid app? |
|---|---|---|
| PHP (interpreter + php-fpm) | PHP License 3.01 | Yes — permissive; keep the notice |
| Caddy | Apache 2.0 | Yes — keep NOTICE + LICENSE |
| Caddy's Go deps | mostly MIT / BSD / Apache | Yes |
| SQLite | Public domain | Yes — no obligation |
| **FFmpeg** (transcode, separate concern) | **LGPL if built without `--enable-gpl`; GPL otherwise** | **Only an LGPL build.** A GPL ffmpeg (x264/x265) forces the whole app to GPL. |

**The one real trap is FFmpeg**, which the queue worker shells out to for
transcoding. It is not part of the web-server layer, but it is the component
that can poison redistribution.

**Current state (17 Sep 2026): ffmpeg is NOT bundled — it is user-installed.**
`config/transcode.php` defaults `FFMPEG_PATH`/`FFPROBE_PATH` to bare `ffmpeg`/
`ffprobe` on PATH; `MediaTranscoder::available()` degrades gracefully when it is
absent (the UI says "ffmpeg is not installed"). So there is **zero ffmpeg
licensing exposure today** — we distribute nothing. Used for: video transcoding
(`MediaTranscoder`, browser-incompatible files → H.264 MP4), subtitle
extraction/conversion (`SubtitleImporter`), and `ffprobe` codec detection.

The catch is what the transcoder *asks for* the moment ffmpeg is bundled:

| Codec requested | Build flag | If bundled |
|---|---|---|
| `libx264` (software H.264 fallback, `resolveEncoder()`) | `--enable-gpl` | ☠️ **GPL — poisons the app** |
| `libmp3lame` (music → mp3) | `--enable-libmp3lame` | LGPL — OK |
| `h264_videotoolbox` (Apple HW), native `aac` | built-in | ✅ clean |

`libx264` is the fallback on every host without Apple's hardware encoder — i.e.
all Windows/Linux and Intel Macs — so a bundled ffmpeg that supports the current
code path is necessarily GPL.

**Recommended for the initial ship: keep ffmpeg user-installed** (option 1),
isolating the GPL question from the server work — the PHP+Caddy bundle stays
100% clean, and only transcoding/subtitles need a manual ffmpeg. Bundled
transcoding is a follow-up: an **LGPL ffmpeg** (no `--enable-gpl`, no x264) with
the `libx264` fallback replaced by **OpenH264** (Cisco, BSD, patent-covered).
Tracked as Step 8.

## The runtime we must bundle, precisely

A wrong PHP build silently disables features, so the bundled PHP must include —
from the audit of `composer.lock` and app code — at minimum:

`mbstring, fileinfo, openssl, curl, intl, gmp, pcre, ctype, filter, hash,
session, tokenizer, pdo_sqlite, sqlite3, gd, pcntl` (pcntl for a real queue
worker), plus a CA bundle. `app/Services/TransferReceiver.php` locates the CA
bundle as `dirname(PHP_BINARY) . '/cacert.pem'`, so the bundled layout must keep
`cacert.pem` next to the PHP binary or that convention breaks.

Static, self-contained PHP builds (e.g. static-php-cli output) are the intended
source — one relocatable PHP per platform, no system dependency.

## Shape

```
                 ┌───────────────────────────────────────────┐
  device  ──TLS──►  Caddy  ──fastcgi──►  php-fpm  ──►  Laravel │
                 │  (:443/:8000)         (unix socket)         │
                 └───────────────────────────────────────────┘
   supervised by:  the desktop app (Tauri sidecar) OR a headless service
                   (launchd / systemd / Windows service)
```

- **Caddy** owns the port and TLS (it can use the existing Tailscale cert, or
  its own local CA). It reverse-proxies `/` to php-fpm and serves
  `public/` static files directly. Upload limits become ours to set (25 MB, not
  2), in one Caddyfile.
- **php-fpm** runs a pool of workers — the multi-process server that ends the
  single-threaded era. This is the part that needs the S-123/S-87/S-93 areas
  re-tested (network probe, connection watcher, the loopback assumptions).
- **Supervision** replaces the three hardcoded launchd plists with a
  per-platform service that (a) uses the bundled binaries by relative path, not
  `Herd/bin/php84`, and (b) exists on Windows (a real service, not just NSSM in
  docs) and Linux (systemd units — currently unwritten).

## Two delivery paths

1. **Inside the desktop app.** Bundle `php`, `php-fpm`, and `caddy` as Tauri
   sidecars (`externalBin` / `resources`, absent today). The desktop app gains
   what it does not have yet: it must **spawn and supervise** these processes
   itself, cross-platform, rather than only `launchctl load` a host plist. This
   is the larger of the two lifts.
2. **Standalone headless installer.** For a server box with no GUI: a script /
   package per OS that drops the same bundled runtime and registers the service
   (launchd / systemd / Windows service). Shares the runtime artifacts and the
   Caddyfile with path 1; differs only in having no window.

## Risks, in order

1. **The single-threaded assumptions.** php-fpm is concurrent. Re-test S-123,
   S-87, S-93 and everything that reads `window.soundchexOffline` / probes the
   network. This is the real risk, not packaging.
2. **Per-platform PHP builds and extensions.** A missing extension fails a
   feature quietly. Needs a startup health check that asserts the required
   extension set and refuses to boot with a clear message otherwise (none exists
   today).
3. **Bundle size.** PHP + Caddy is ~50–80 MB per platform. Acceptable for a
   media server; note it.
4. **TLS story.** Caddy's own CA vs the Tailscale cert vs a real domain — the
   existing `RemoteAccess.md` / `DirectRemoteAccess.md` reasoning has to be
   reconciled with Caddy terminating TLS instead of `tailscale serve` proxying
   to plaintext 8000.
5. **Migration for existing installs.** The three current plists point at Herd;
   an upgrade must remove them and install the new supervised service without
   leaving a second server bound to 8000.

## Verify

Nothing is done until, on each of macOS / Windows / Linux:

- A fresh machine with **no PHP and no Herd** installs the product and serves
  `/app` over HTTPS.
- A **5 MB** upload succeeds (the cap is ours now).
- The queue worker and scheduler run under the new supervision.
- The S-123/S-87/S-93 network-probe paths pass against the concurrent server.
- `THIRD-PARTY-LICENSES.txt` is present and complete; the bundled ffmpeg (if
  any) is an LGPL build.
