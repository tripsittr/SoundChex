# Working on SoundChex from a Windows machine

For building and running the client locally, so a change can be tested in
minutes instead of waiting on a release.

If you only want to *run* SoundChex, don't do any of this — download the
installer from the releases page.

## What to install

Four things. Everything else comes from the repo.

1. **Visual Studio Build Tools** — the C++ compiler Rust links through.
   Install "Desktop development with C++". This is the large one (~6 GB) and
   the one people forget; without it `cargo build` fails with a linker error
   that does not mention Visual Studio.

   <https://visualstudio.microsoft.com/visual-cpp-build-tools/>

2. **Rust** — <https://rustup.rs>. Take the defaults; it picks the MSVC
   toolchain automatically.

3. **Node 20+** — <https://nodejs.org>.

4. **PHP 8.4 and Composer** — the client bundles the web app's compiled
   assets, so the build runs `npm run build`, which needs PHP for the Blade
   compilation step.

   ```powershell
   winget install PHP.PHP.8.4
   winget install Composer.Composer
   ```

**WebView2** is already on Windows 11 and recent Windows 10. If the app
launches and shows nothing at all — no window, no error — that is the first
thing to check: <https://developer.microsoft.com/microsoft-edge/webview2/>

## Getting the code

```powershell
git clone https://github.com/tripsittr/SoundChex.git
cd SoundChex
composer install
npm ci
npm run build
```

## Running it

```powershell
npm run tauri dev
```

This opens the app with a live reload, and — the point of all this — prints
Rust panics and WebView errors straight to the terminal. The installed release
build shows none of that, which is why "it opens and does nothing" is so hard
to diagnose from a packaged app.

To build an installer the way CI does:

```powershell
npm run tauri:build
```

The output lands in `src-tauri\target\release\bundle\`.

## When something does not work

Run `npm run tauri dev` and copy the terminal output. That is worth more than
any description of the symptom: a packaged Tauri app that fails at startup
exits silently, while `dev` prints the reason.

Logs from an installed build are at:

```
%LOCALAPPDATA%\SoundChex\logs
```

That path was wrong until 0.2.0 — it pointed at a Linux directory, so older
builds wrote their logs nowhere findable (S-419).

## What has never been tested here

Be sceptical of all of it. As of 0.2.0 no part of SoundChex had been *run* on
Windows — it compiled in CI and nothing more:

- The client app itself.
- The bundled server, which on Windows serves with FrankenPHP instead of
  php-fpm because PHP ships no fpm SAPI there (S-418).
- The `%LOCALAPPDATA%` log path.
- Starting with the machine, which writes a `Run` key (S-419).

If one of those is broken, it is broken for everyone on Windows, and finding
it here is the point.
