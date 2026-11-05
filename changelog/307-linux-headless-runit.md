# 307 — Run SoundChex Server headless under runit, and publish it for Linux arm64

**Merged** — · **Issues** #521, #522, #523, #524

SoundChex Server can now run with no window, `soundchex-server --headless`, so
a service manager can keep the library up with nobody logged in. HatCOS (Void
Linux, runit) is the first host. The Linux arm64 app is built in CI and
attached to every `server-v*` release with checksums.

## What changed

### Headless mode

`soundchex-server --headless` (or `serve`), with `--listen` and `--data-dir`
(or `SOUNDCHEX_LISTEN` / `SOUNDCHEX_DATA_DIR`). It is the window's own code
path without the window. The resource directory comes from Tauri's resolver,
the data directory is the one the window uses, and provisioning, config
rendering and the supervisor are shared. It is decided before Tauri starts,
because building a window needs a display and runit at boot has none.

It runs in the foreground and logs to stdout/stderr, each child's lines tagged
`[php-fpm]`, `[caddy]`, `[queue]` or `[scheduler]`. SIGTERM, SIGINT and SIGHUP
stop everything. It refuses to start when a headless stack is already running
or when the HTTP or php-fpm port is taken, instead of respawning a Caddy that
can never bind.

### Stopping cleanly, window included

The supervisor used to SIGKILL its children. Now each child leads its own
process group and stopping sends SIGTERM to the group, waits ten seconds, then
SIGKILLs what is left, in reverse start order. Before, the queue's own workers
(children of `queue:workers`, not of the supervisor) were not reached at all.
Windows is unchanged: its job object already does this.

### The window defers to a stack it did not start

On open, the Server app asks for a headless stack (`<data>/server/headless.json`,
pid checked alive) and for the runit service (`sv status soundchex-server`). If
either exists, it shows a "Running as a service" card with the state and
Start / Stop / Restart through `sv` (Stop only, by SIGTERM, for a headless
process started by hand). It no longer starts its own stack or jumps to the
admin panel. `server_start` refuses in the same situation.

### Services page on runit

When `/run/runit` or `sv` exists and the `soundchex-server` service does, the
admin Services page shows that one service and starts and stops it with
`sv up|down`, reading the state back afterwards, because sv exits zero for a
request it only accepted. Stopping warns that it takes this page down. The log
button reads `/var/log/soundchex-server/current` (`SOUNDCHEX_RUNIT_LOG`). The
service name is `SOUNDCHEX_RUNIT_SERVICE`.

### Linux release assets (arm64)

`build-server.yml` builds the Linux Server app on `ubuntu-24.04-arm` and
attaches `soundchex-server-app-linux-aarch64.deb` and `.AppImage`, each with a
`.sha256`, to the release. The names are fixed because HatCOS fetches a fixed
URL. A Linux-only config overlay names the binary `soundchex-server`; it was
`soundchex`, the same path the client package installs. The release job now
fails if the Linux app is missing, but a macOS or Windows app failure no longer
holds the runtime release back. x86_64 Linux is held for now.

## Worth knowing

- **A packaged POSIX install could not start.** `render_config` read templates
  from `<app>/server/templates`, and the unpacked payload has no `server/`, so
  any install not pointed at a checkout failed with "missing template", in the
  window as well. It now reads the bundle's own `runtime/templates` first. The
  Mac it was built on always served from a checkout, which is why it never
  showed.
- The bundled `bin/` is now first on every child's `PATH`. Subtitle import
  calls `ffmpeg`/`ffprobe` by bare name and so never found the bundled ones. On
  macOS it now uses the bundled ffmpeg rather than Homebrew's.
- php-fpm's address can be set with `SOUNDCHEX_FPM` (default `127.0.0.1:9100`).
- The Linux runtime bundles `ffmpeg` and `ffprobe` (BtbN static GPL build), as
  the macOS one does. Neither bundles Chromaprint's `fpcalc`. These are looked
  up on `PATH` and degrade a feature when missing: `fpcalc` (chromaprint,
  AcoustID), `tesseract` (OCR), `pdftoppm`/`pdftotext`/`pdfinfo`/`pdfimages`/
  `pdftohtml` (poppler, books), and `which`, which the app uses to probe for
  several of them.
- `server/supervisor/runit/` is a reference run script; HatCOS's package owns
  the real one.

## Still wrong

- Nothing here has run on Linux yet. Headless start, first-run provisioning,
  the double-start refusal, the PATH change and the SIGTERM shutdown (including
  the queue's worker grandchild) were exercised on macOS against a scratch data
  directory. The aarch64 CI build, the .deb/AppImage and runit control are first
  seen on HatCOS.
- The window's managed view was checked in a browser with Tauri mocked, not in
  the packaged app.
- `storage/logs/laravel.log`, Caddy's access log and php-fpm's error log still
  go to files, not stdout.
- An AppImage under runit needs FUSE or `--appimage-extract-and-run`; the .deb
  is the better fit for a service.

## Tests

CI on the branch (run 42118908535) first failed in two places, both fixed
here: the arm64 runner image has no `xdg-utils`, which AppImage packaging
needs, and Windows did not compile because `current_user_home` existed only
on Unix. Windows cannot be type-checked from a Mac (`ring` needs the Windows C
headers), so the Unix-only items were checked against their `cfg` guards by
hand. CI is what confirms it.

Rust: 32 pass (new: argument parsing, stale state files, `sv status` parsing).
PHP: 1,818 pass in a clean worktree. The one failure there is the Tailwind CLI
check, which needs `node_modules`, absent from that worktree. In the main
checkout, untracked duplicate `… 2.php` migrations break the suite independently
of this change. Breaking the runit read-back guard on purpose turns
`HostServicesRunitTest` red. Vitest: 127 pass. Playwright: not run, since
nothing under `resources/` changed.
