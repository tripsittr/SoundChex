# A desktop window that exists

The client opened no window on Windows, macOS or Linux. The process started, the
webview helpers appeared, nothing was shown and nothing was logged — because
nothing failed.

## The window was declared in config until it was deleted

`tauri.conf.json` carried an `app.windows` entry from the first Tauri commit
(`995d62c`) until `ab536b1`, "Inject the Tauri bridge into the remote origin so
native storage works". That commit began creating the `main` window in Rust so
the bridge could be injected into every frame, and removed the config entry
because a config window plus a programmatic one of the same label is a duplicate
that panics at startup.

The creation that replaced it is `#[cfg(mobile)]`. So from `ab536b1` onward
nothing on desktop created a window at all, and the comment left behind said the
opposite:

```rust
// Desktop windows come from the Tauri config; creating one here would
// duplicate the default `main` window and panic at startup.
#[cfg(mobile)]
```

The first half of that was no longer true when it was written.

## What it looked like

Measured on Windows, with `npm run tauri dev`:

```
Finished `dev` profile in 2m 31s
Running `target\debug\soundchex.exe`
```

and then nothing. Three `soundchex.exe` processes, all `Responding=True`, all
reporting a main window of `0x0` with an empty title — those are the WebView2
helpers. No panic, no stderr, no log file, because from Tauri's point of view
the app started correctly and was simply asked to show nothing.

This is why it survived into a release. `build-client.yml` says it plainly:

> It does not run the resulting binaries — a build succeeding is the signal.

The build was never broken.

## The fix is conditional, and that part matters

Desktop now builds its window in `setup()`, with the values the deleted config
carried. It is guarded on there not already being one:

```rust
if app.get_webview_window("main").is_none() {
```

Without that guard this fixes the client and breaks the Server app.
`tauri.server.conf.json` declares its own window — unlabelled, so Tauri calls it
`main` — and creating a second would hit exactly the panic `ab536b1` was avoiding.
In a packaged build that panic is an exit with no message, which is the same
silent failure in a new place. Verified both ways: the client creates its window,
the Server app keeps the one from its config and its title reads
`SoundChex Server`.

No bridge injection on desktop. Desktop never had it, and `withGlobalTauri`
already supplies the global on a local page.

## Worth knowing

- **This does not reach existing installs.** The updater refuses an update with
  no minisign signature, and nothing generates one — no `TAURI_SIGNING_PRIVATE_KEY`
  in any workflow, no `latest.json` produced anywhere. `tauri.conf.json` admits
  as much in its own comment. Anyone on 0.2.0 needs the new installer by hand.
- A new download does get it automatically, once a `client-v*` tag builds.

## Still wrong

- **`resolve_layout` lies about dev.** Its comment says `app_dir` in
  `tauri dev` is "the repo root two levels up from `src-tauri`"; the code falls
  back to `resource_dir`, which holds no `artisan`. Running the Server app in dev
  needs `SOUNDCHEX_APP_DIR` set by hand. Not fixed here.
- **The supervisor does not reap its children.** Closing the Server app left
  `frankenphp.exe` and both `php.exe` workers running and still holding port
  8000. Observed, not fixed.
- Unverified on macOS and Linux. The defect is platform-neutral and so is the
  fix, but it has only been run on Windows.

## Tests

PHP: 1120 tests, 1073 passed, 32 skipped. 7 failures and 8 errors, all
pre-existing and environmental — confirmed by running the same filter with the
change stashed and getting an identical set: `gd`/`exif` absent from the local
PHP, `rename()` over an open file on Windows, a path-traversal assertion that
expects POSIX separators, and a `--force` option a test expects but the command
does not define.

No automated test covers "a window appears". The verification was the app
opening, and the Server app's title bar reading `SoundChex Server`.
