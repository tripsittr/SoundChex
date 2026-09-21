# 139 — Build the desktop client on Windows and Linux in CI (S-33)

The desktop client (the Tauri app) was only ever built on macOS, so a change that
broke the Windows or Linux build was invisible until someone tried it — the gap
S-33 describes. It now compiles on all three in CI.

## What this adds

- **`.github/workflows/build-client.yml`** — builds the client on `macos-latest`,
  `windows-latest` and `ubuntu-latest` on every push to `main` (when the client
  or its build touches) and on `client-v*` tags. Linux installs the WebKitGTK
  stack Tauri needs; the Rust build is cached between runs. A break now fails in
  CI rather than on a user's machine, and tagged builds attach the installers.

## What this does and doesn't do

It proves the client **compiles and bundles** on each OS — the thing that used to
go untested. It does **not** run the resulting binary: no one has launched the
Windows or Linux build on real hardware, and CI cannot do that. So "builds in CI"
is an honest step up from "never built", not a claim that the app runs there.

The `BuildingOnEachPlatform.md` table is updated to say exactly this — Windows and
Linux move from "no" to "in CI (compiled, not yet run on hardware)".

## Note

The bundled *server* runtime already built on all desktop OSes
(`build-server.yml`, S-151); this is the *client app*, which did not. Android
still has no Tauri project and is unchanged.

## Tests

No app code changed — CI and docs only. Full suite: 797 passed.
