# Tauri shell

A native wrapper around the existing web app, for macOS, Windows, Linux, iOS
and Android. Chosen because it covers every non-TV target from one codebase —
see [AppDistribution.md](AppDistribution.md) for the alternatives considered.

## What it is

**Deliberately thin.** `src-tauri/src/lib.rs` is a dozen lines: build a window,
run it. There are no custom Rust commands, and the capability file grants
nothing beyond Tauri's core defaults.

That is the design, not an unfinished state. The application is the Laravel
server; the shell exists to provide a launcher icon, an app-switcher entry,
and — the real prize — storage the browser will not evict. There is one
frontend, already tested by the Playwright suite, and no second implementation
to drift out of step with it.

## Why it does not hardcode a server address

A self-hosted app has a different URL per household, so baking one in would
mean rebuilding the binary for every install.

Instead `public/tauri/index.html` is a small connect screen: it asks for the
address once, stores it in `localStorage`, and redirects. Every launch after
the first goes straight through.

It does two things worth keeping:

- **Normalises the input.** A bare hostname gets `https://`, and a path is
  discarded. `http://` is rejected outright — it would otherwise fail silently
  against the CSP rather than explaining itself.
- **Checks the server answers before navigating.** Without this a typo lands on
  a blank page with no way back, because the connect screen has already been
  replaced by the failed navigation.

The CSP allows `https:` broadly rather than naming one host, because the host
is user-supplied and unknown at build time. `http:` is not permitted.

## Why this lives in the main repo

Considered and settled, so it does not get relitigated.

The shell is **six source files** — `lib.rs`, `main.rs`, `build.rs`,
`Cargo.toml`, `tauri.conf.json`, a capability file — plus the connect page and
generated icons. **0.9 MB tracked, against 468 files in the repo.** Build
output (`target/`, `gen/`) is gitignored and never enters history.

Size was never the argument. The reason is that **the app and the server change
together**. The offline-first work adds an API whose only consumer is this
client: every endpoint has a caller, and every payload shape has a test. In two
repositories each of those becomes a coordinated pair of pull requests with
version skew in between — the client shipping against an endpoint the server
has already changed. In one, a single commit moves the endpoint, its consumer
and the test that proves they still agree.

Splitting would be right if the client were maintained separately, released on
its own cadence, or written by different people. None of those apply here.

## Building

```bash
npm run tauri:build     # current platform
npm run tauri:dev       # run against a live server, with devtools
```

`frontendDist` points at `public/tauri`, which is a static file the Laravel
build never touches — nothing to compile before a Tauri build.

### Per-platform

Tauri cross-compiles poorly. Each desktop OS wants its own machine or CI
runner; GitHub Actions covers all three free for public repositories.

| Target | Needs | Output |
| --- | --- | --- |
| macOS | This machine | `.app` + `.dmg` (both verified) |
| Windows | Windows machine or CI | `.msi`, `.exe` (NSIS) |
| Linux | Linux machine or CI | `.deb`, `.rpm`, `.AppImage` |
| iOS | Mac + Xcode + paid Apple account | `.ipa` |
| Android | Android SDK + NDK | `.apk` |

Mobile targets need initialising first — `tauri ios init`, `tauri android
init` — which generates `src-tauri/gen/`. That directory is gitignored; it is
regenerated, not authored.

### The DMG needs macOS Automation permission

Tauri's DMG script drives **Finder through AppleScript** to arrange the window.
Without Automation access for the calling terminal it fails with `AppleEvent
timed out (-1712)`.

Granting that permission (System Settings → Privacy & Security → Automation)
is all it needs — the target is enabled and builds cleanly.

Two things that break it if it starts failing again:

- **A stale mounted image.** `hdiutil info` will list a leftover `/dev/diskN`;
  `hdiutil detach <disk> -force` clears it.
- **Running from a terminal that has not been granted Automation access.** The
  permission is per-application, so a different terminal needs its own grant.

## Signing

Unsigned builds work; they warn once.

- **Windows** — SmartScreen says "unrecognised app". One click. Removing it
  costs ~$100–300/yr for an Authenticode certificate. Not worth it here.
- **macOS** — right-click → Open the first time.
- **iOS** — genuinely requires the $99/yr Apple account. Free provisioning
  expires every 7 days, which makes an unpaid install unusable in practice.

## Verified

- Release build compiles clean; the binary is 2.8 MB, the `.app` 3.0 MB and
  the `.dmg` 1.6 MB.
- The app launches and stays running.
- The DMG passes `hdiutil verify` (valid checksum), contains `SoundChex.app`
  and the `/Applications` drop-link, and the app **launches from the mounted
  image** — checked rather than assumed from the file existing.
- The webview genuinely renders and issues network requests — confirmed by
  pointing the window at a local probe server and observing the GET in its
  access log, rather than inferring it from the process staying alive.

## Not done yet

- **iOS and Android have not been built.** Xcode and the Android SDK are not
  installed on this machine. The configuration and icons for both are in place.
- No CI workflow for Windows/Linux builds.
- The connect screen has no "change server" affordance once a host is saved —
  clearing app storage is currently the only way back to it.
