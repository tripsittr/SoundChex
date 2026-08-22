# A server app, and client apps everywhere else

One host application that runs the library and keeps its services alive, and a
client for every device that plays from it.

## Why one codebase, not two

The concern behind splitting was server bloat in the client apps. Measured:

| | |
| --- | --- |
| iOS client app (IPA) | **1.9 MB** |
| Embedded frontend inside it | 144 KB |
| Laravel `app/` | 1.0 MB |
| Laravel `vendor/` | **134 MB** |

**None of the server ships in the client.** A Tauri app is a native shell around
a system webview: the frontend is compiled in, and the PHP stays on the host.
The client is already the size of a small image, and splitting the repository
would not remove a single byte from it.

What it *would* cost: two histories to keep in step, every shared component
duplicated or extracted into a third package, and a change to the media centre
made twice. The parts that genuinely differ between server and client are the
window configuration and the icon.

Tauri supports this directly. `tauri build --config` merges an override file
over the base configuration, so a second product is a JSON file and a build
script rather than a fork.

## What already exists

Most of this is built and needs assembling rather than writing.

- **The media centre** — the client UI, working on desktop and iOS.
- **The admin panel** — Filament at `/admin`, which is already the server UI:
  scanning, duplicates, OCR, network, library settings.
- **The Tauri shell** — one target, currently pointed at the media centre.
- **Three launchd plists** — web server, queue worker, scheduler. Written and
  valid, but installed by hand, which is why the server keeps dying.

## Plan

### 1. Two build targets from one config (~half a day)

`src-tauri/tauri.server.conf.json` overrides `productName`, `identifier`,
window title and the URL the window opens — `/admin` rather than the connect
screen. Two npm scripts, `build:client` and `build:server`.

Both keep the same Rust shell. The server app is a different window onto the
same application, not a different application.

### 2. The server app manages its own services (~1 day)

The reason this project exists. The server app installs the three launchd
agents on first run and shows their state: running, stopped, or failed, with a
button for each and a tail of the log.

This is what replaces `launchctl load` typed by hand, and what stops a stopped
web server presenting as a white screen on a phone with no explanation.

On Linux the same thing through systemd user units; on Windows, a scheduled
task. The plists already exist and are correct — the work is installing and
querying them rather than authoring them.

### 3. A status page worth opening (~1 day)

The server app opens on something that answers "is it working": services up,
last scan and what it found, queue depth, library counts, free disk, the
addresses the server can be reached on, and when the last backup ran.

Most of this exists in scattered places — the admin dashboard, the network
page, `db:backup`. This is a view over them, not new plumbing.

### 4. Client builds per platform (~1–2 days)

- **macOS, Windows, Linux** — the same Tauri target, already working on macOS.
  Windows and Linux need a machine to build on, which is the only real cost.
- **iOS** — working today.
- **Android** — same Tauri target; needs the SDK installed and a device.
- **TV** — deliberately last, and not Tauri. Android TV can run the Android
  build with a remote-friendly layout; Apple TV needs a native tvOS client
  against the JSON API, which is a separate project and should be judged on its
  own.

### 5. Tests (~1 day)

- Both configs produce a valid Tauri build.
- The server target opens the admin panel and the client target does not.
- Service install is idempotent — running it twice does not produce two agents.
- Service state is read correctly when an agent is running, stopped, and failed.
- The client build contains no PHP, which is the claim this plan rests on.

**Total: ~4–5 days**, most of it in the service management that makes the server
app worth having.

## Deliberately not doing

- **Splitting the repository.** It removes nothing from the client and doubles
  the maintenance. If a future client genuinely needs to diverge — a native tvOS
  app, say — that is the moment to split, and only that client.
- **Bundling PHP into the server app.** Herd already provides it, and shipping a
  second PHP runtime is exactly the bloat this plan exists to avoid.
- **Multi-host libraries.** Raised alongside this and deliberately deferred: it
  means host discovery, catalogue reconciliation, ownership of individual files,
  and conflict resolution when two hosts disagree. Neither Plex nor Jellyfin
  attempts it. If the goal is reaching the library from more places, Tailscale
  already does that; if it is more storage, another disk on one host is far
  simpler.

## Not started
