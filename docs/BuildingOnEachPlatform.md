# Building on each platform

What every target needs, what is proven, and what is not.

The web app is platform-neutral — Blade, CSS and JavaScript served from a
Laravel app that runs anywhere PHP does. What differs is the shell around it
and the handful of places the server asks the operating system a question.

---

## What has actually been built

| Target | Built | Notes |
| --- | --- | --- |
| **macOS** | yes, repeatedly | `.dmg` and `.app`, both the client and the server app |
| **iOS** | yes, on device | Needs a paid Apple account for a year-long profile |
| **Windows** | **no** | Toolchain documented below, never run |
| **Linux** | **no** | Same |
| **Android** | **no** | No Tauri project generated yet |

Anything marked "no" is a set of instructions, not a promise. They are written
from Tauri's requirements rather than from a build anyone has watched succeed
here.

---

## All platforms

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate

npm run build
```

**FFmpeg must be on the PATH.** Transcoding, caption extraction and duration
reading all shell out to `ffmpeg` and `ffprobe` by name.

---

## Windows

> **Setting one up from scratch?** Follow
> **[SettingUpOnWindows.md](SettingUpOnWindows.md)** instead — same ground, in
> order, with a check after each step and the silent failures called out. What
> follows is the reference version.

**Toolchain**

1. [Rust](https://rustup.rs) — the installer offers the MSVC toolchain, which
   is the one Tauri needs.
2. **Visual Studio Build Tools**, with "Desktop development with C++".
   The linker comes from here; Rust alone is not enough.
3. **WebView2** — present on Windows 11 and most Windows 10. Tauri's installer
   pulls it in if missing.
4. **PHP 8.4+**, **Node 22+**, **Composer**.
5. **FFmpeg** — `winget install Gyan.FFmpeg`, then reopen the terminal so the
   PATH change is picked up.

**Setting the project up**

```powershell
git clone https://github.com/tripsittr/SoundChex.git
cd SoundChex

composer install
npm install

copy .env.example .env
php artisan key:generate
php artisan migrate
```

Then three things the generic steps do not cover:

**1. The storage symlink.** `public/storage` is a symlink, it is gitignored, and
a fresh clone does not have one. Without it every avatar and artist image
404s.

```powershell
php artisan storage:link
```

Windows restricts symlink creation. If this fails, either turn on
**Settings → System → For developers → Developer Mode**, or run the command
from an elevated terminal.

**2. Where the media is.** Add to `.env` — absolute paths, comma-separated,
and backslashes are fine because the split is on commas:

```
LIBRARY_WATCH_FOLDERS=D:\Media\Music,D:\Media\Films
```

This key is not in `.env.example`, so it has to be added by hand.

**3. Point the app at itself.** `APP_URL` defaults to `http://localhost` and is
what the app advertises to other devices:

```
APP_URL=http://localhost:8000
```

**Running it**

```powershell
php artisan serve
php artisan queue:work        # separate terminal — enrichment, transcoding
php artisan schedule:work     # separate terminal — scanning, backups
```

The Services page that manages those two on macOS is launchd-based and does
not work here; the page says so rather than failing.

**Building the app**

```powershell
npm run build                 # the web assets, first
npm run tauri:build           # the client
npm run build:server          # the host app
```

Output lands in `src-tauri\target\release\bundle\` as `.msi` and `.exe`.

**Tests**

```powershell
php artisan test              # 334, all portable
npx vitest run                # 87, all portable
npx playwright test           # needs bash
```

The browser tests shell out to `tests/e2e/bootstrap.sh`, so they need Git Bash
or WSL. The other two suites are the ones worth running first anyway: if they
fail, the problem is the PHP or Node install rather than the port.

**What will not work**

- **The Services page** — start/stop/logs for the queue and scheduler. It is
  built on launchd and has no Windows equivalent yet. The page detects this and
  says so rather than failing. Run `php artisan queue:work` and
  `php artisan schedule:work` yourself, or set them up as Windows services with
  [NSSM](https://nssm.cc).

**What was fixed for Windows and has not been run there**

- LAN address detection used `ipconfig getifaddr en0`, which is macOS-only.
  Now asks PowerShell for the interface with a default gateway.
- Tailscale was looked for in Homebrew paths. Now checks
  `C:\Program Files\Tailscale\`.
- Absolute paths were detected by a leading separator, so `C:\Users\...` read
  as relative and **every file in the library** would have been unreadable.
  Now recognises drive letters and UNC shares.

That last one is the reason to try a small library first: it is the kind of
thing that either works completely or fails completely.

---

### When the DMG step fails

`npm run build:server` can fail at `bundle_dmg.sh` while the `.app` itself
builds fine. Each failure leaves a mounted disk image and `rw.*` files in
`src-tauri/target/release/bundle/macos/`, which break the next attempt — so it
gets worse rather than better on a retry.

```bash
hdiutil info | grep -B14 "rw\." | grep -oE "^/dev/disk[0-9]+" | xargs -n1 hdiutil detach -force
rm -f src-tauri/target/release/bundle/macos/rw.*

# The .app alone, which is all a local install needs.
npm run tauri build -- --config src-tauri/tauri.server.conf.json --bundles app
cp -R "src-tauri/target/release/bundle/macos/SoundChex Server.app" /Applications/
```

**Tauri exits 0 on this failure.** Read the output rather than the exit code —
it reports "failed to bundle project" and then exits successfully.

## Linux

**Toolchain**

```bash
sudo apt install libwebkit2gtk-4.1-dev build-essential curl wget file \
  libxdo-dev libssl-dev libayatana-appindicator3-dev librsvg2-dev ffmpeg
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
```

**Build**

```bash
npm run tauri:build
```

Produces `.deb`, `.rpm` and `.AppImage`.

**What will not work** — the Services page, as on Windows. systemd units would
be the equivalent and are not written.

---

## Android

No Tauri Android project has been generated. Before anything can be built:

```bash
npm run tauri android init
```

**Needs** Android Studio, the NDK, `JAVA_HOME` and `ANDROID_HOME` set, and the
Rust targets:

```bash
rustup target add aarch64-linux-android armv7-linux-androideabi \
  i686-linux-android x86_64-linux-android
```

**Expect real work beyond the build.** The iOS shell carries assumptions about
safe-area insets, the connect screen and WebView storage that were arrived at
by hitting them on a device. Android's WebView is a different engine with
different limits — particularly around storage eviction, which is already the
largest open problem on iOS.

---

## Why a Mac cannot build the Windows app

`tauri.conf.json` lists `msi` and `nsis` among its targets, which reads as
though one machine could produce them all. It cannot: the list means "produce
these when building on a platform that supports them".

A Windows binary needs the MSVC linker and the Windows SDK, and the `.msi`
bundler is WiX, which expects Windows. `cargo-xwin` covers the Rust half and
not the bundling.

So: build on the platform, or use a CI runner per platform. There is no CI
configured yet.

---

## Verifying a build on a new platform

Worth doing in this order, because each depends on the last:

1. `php artisan test` — 334 tests, no platform-specific ones. If these fail,
   the problem is the PHP install rather than the port.
2. `php artisan serve`, then open `/app`. Proves the library reads.
3. Point it at a **small** watch folder and scan. Proves paths resolve — the
   single most likely thing to be wrong on a new platform.
4. Play something. Proves ffmpeg is found and streaming works.
5. Build the shell and open it. Proves the connect screen finds the server.
