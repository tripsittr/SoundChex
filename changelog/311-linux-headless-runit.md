# 311 — Run SoundChex Server headless under runit, and publish it for Linux arm64

**Merged** — · **Issues** #521, #522, #523, #524

SoundChex Server can now run with no window, `soundchex-server --headless`, so
a service manager can keep the library up with nobody logged in. HatCOS (Void
Linux, runit) is the first host. The Linux app is built for x86_64 and
arm64 in CI and attached to every `server-v*` release with checksums.

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

### Knowing a headless stack is running

A headless process holds an exclusive `flock` on `<data>/server/headless.lock`
for its whole life, taken before first-run setup, and that lock is what
"running" means. The first version trusted a pid recorded in `headless.json`.
A power cut left that file behind, and after a reboot the pid was likely to
belong to some other live process. Headless then refused to start, runit
restarted it every second, and the library stayed down until someone deleted
the file. The kernel releases a lock however its holder dies. `headless.json`
still records the pid and address for the window to show, and is now written
atomically.

### Stopping cleanly, window included

The supervisor used to SIGKILL its children. Now it sends SIGTERM, waits six
seconds, then SIGKILLs whatever is left. Six is under the seven seconds runit's
`sv force-stop` allows at shutdown; at ten, every reboot SIGKILLed the
supervisor first.

Headless, each child leads its own process group, so the signals reach the
queue's own workers, which the old kill never touched. The wait lasts until
the whole group is gone. On Linux each child also gets a parent-death signal,
so a supervisor killed outright cannot leave the stack holding the ports. The
window keeps its children in its own group as before, so Ctrl-C on `tauri dev`
still reaches them. Windows is unchanged: its job object already does this.

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

### Linux release assets (x86_64 and arm64)

`build-server.yml` builds the Linux Server app on `ubuntu-latest` and
`ubuntu-24.04-arm` and attaches `soundchex-server-app-linux-<arch>.deb` and
`.AppImage` (`x86_64`, `aarch64`), each with a `.sha256`, to the release. The names are fixed because HatCOS fetches a fixed
URL. A Linux-only config overlay names the binary `soundchex-server`; it was
`soundchex`, the same path the client package installs. The release job now
fails if the Linux app is missing, but a macOS or Windows app failure no longer
holds the runtime release back.

## Worth knowing

- **A packaged POSIX install could not start.** `render_config` read templates
  only from `<app>/server/templates`, and the unpacked payload has no
  `server/`, so any install not pointed at a checkout failed with "missing
  template", in the window as well. It now falls back to the bundle's own
  `runtime/templates`. A checkout's copy still wins where there is one, because
  a locally built app's bundled copy is whatever was staged when it was built:
  on this Mac, a September Caddyfile with an unquoted root and the old 25 MB
  cap.
- The Services page now shows why a start or stop failed. It read `lastError`
  from a second, fresh `HostServices` and always showed nothing. Refused `sv`
  calls are logged with the action and what `sv` printed.
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
  directory. The CI builds, the .deb/AppImage and runit control are first
  seen on HatCOS.
- The window's managed view was checked in a browser with Tauri mocked, not in
  the packaged app.
- `storage/logs/laravel.log`, Caddy's access log and php-fpm's error log still
  go to files, not stdout.
- The arm64 AppImage leaves out the bundled `tailwindcss`. linuxdeploy aborts
  when `ldd` fails on that Bun executable on arm64. Without it, plugins keep
  the CSS they ship. The arm64 `.deb` and both x86_64 packages include it.
- An AppImage under runit needs FUSE or `--appimage-extract-and-run`; the .deb
  is the better fit for a service.

## Tests

CI on the branch (run 42118908535) first failed in two places, both fixed
here: the arm64 runner image has no `xdg-utils`, which AppImage packaging
needs, and Windows did not compile because `current_user_home` existed only
on Unix. Windows cannot be type-checked from a Mac (`ring` needs the Windows C
headers), so the Unix-only items were checked against their `cfg` guards by
hand. CI is what confirms it.

The second run built the arm64 .deb, then the AppImage failed in linuxdeploy
with no detail. The Linux build now sets `NO_STRIP=true` (linuxdeploy's old
`strip` is the known cause on Ubuntu 24.04) and `--verbose`, so another failure
there says why. The third run showed two causes: on arm64, linuxdeploy's gtk
plugin aborts when `ldd` fails on the bundled `tailwindcss`; on x86_64 the
package was fine but the release step's check expected `./usr/bin/…` where
`dpkg-deb` lists `usr/bin/…`. Both are fixed: the .deb and AppImage are built
separately, and the check accepts either form.
The fourth run built all four Linux packages; its smoke test then ran a bare
`soundchex-server --help`, which was read as "open the window" and aborted on
GTK with no display. A bare `--help` now prints the usage. The packages are
uploaded before the checks, so a failing check still leaves them to download,
and `reuse_runtime_from_run` on a manual run skips rebuilding the runtime.

A review of the PR found the stale-pid wedge, the 10 s grace outliving runit's
7 s, the template order, the state file arriving after first-run setup, and the
lost `lastError`. All are fixed above.

Rust: 34 pass (new: argument parsing, the lock, `sv status` parsing). Breaking
the lock check on purpose turns both lock tests red. On this Mac, a leftover
`headless.json` naming a live pid no longer blocks a start, a second instance
is refused with the holder's pid, and SIGTERM stops everything in under a
second.
PHP: 1,826 pass, 30 skipped, none fail, in a clean worktree of this branch. In
the main checkout, untracked duplicate `… 2.php` migrations break the suite
independently of this change. `HostServicesRunitTest` (14) passes,
including a render of the runit branch of the Services view and the page
reporting why a stop failed. That test fails with the old `lastError` code put
back. Breaking the runit read-back guard on purpose also turns it red.
Vitest: 127 pass. Playwright: not run. The Services view did change, but no
browser spec covers that page, so the render test stands in for it.
