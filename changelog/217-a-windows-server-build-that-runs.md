# A Windows server build that runs

The Server app built on Windows before this. It produced an installer, and the
installer produced something that could not serve a single request. Three
separate faults, each of which was individually enough.

## Nothing built the Server app in CI

`build-client.yml` builds the client. `build-server.yml` builds the PHP runtime
the Server app bundles. Neither built the Server app itself, so the only way it
had ever been produced was `scripts/reinstall-server-app.sh` — which refuses to
run anywhere but macOS, on a developer's machine. A break on Windows or Linux
could not be seen until someone tried it there.

There is now an `app` job, matrixed over macOS, Windows and Linux. It downloads
the runtime the earlier jobs built, stages it, asserts the staging actually
happened, and builds. Like `build-client.yml` it does not run the result: a
build succeeding is the signal.

## The resources glob dropped every PHP extension

`tauri.server.conf.json` bundled `runtime/bin/*`, which does not recurse. On
Windows the dynamic extensions live in `runtime/bin/ext`, so all 36 DLLs were
left out — while a `php.ini` naming them was included, because it sits one
level up. Now `runtime/bin` and `runtime/templates` are mapped as directories.

Destination paths are unchanged (`resources/runtime/bin/…`), which matters:
`resolve_layout` in `lib.rs` hardcodes `resource_dir/runtime/bin`. The first
attempt at this mapped them to `runtime/`, which silently dropped the `bin`
level and would have broken all three platforms at once.

Verified by reading the generated `installer.nsi`: 41 `runtime\bin\ext\…`
entries where there were none, and `frankenphp.exe` still at
`runtime\bin\frankenphp.exe`.

## The runtime bundle could not start

`package-runtime.sh` copied `frankenphp.exe` out of the official archive and
nothing else. FrankenPHP's Windows build is dynamically linked — `deplister`
reports `php8ts.dll`, `brotlicommon.dll`, `brotlidec.dll`, `brotlienc.dll`,
`libwatcher-c.dll` and `pthreadVC3.dll` — so that binary cannot start at all.
The script's own check only asserted the file was non-empty.

The whole archive payload is now copied, and the check runs
`frankenphp.exe version` and `php.exe --version` instead of measuring file
size. A missing DLL now fails in CI rather than on a user's machine with no
message.

Windows also no longer builds PHP with static-php-cli. The FrankenPHP archive
carries a complete PHP, and that is the one Windows arrangement that has been
seen to serve this app. That removes the MSVC dev environment, the retry loop
around a flaky `doctor`, the `windows-2022` pin (spc rejects VS 18) and about
forty minutes — to produce a `php.exe` that was being shipped next to a
frankenphp that could not run anyway.

Caddy is no longer bundled on Windows: FrankenPHP is the web server there, the
supervisor's Windows process list is `frankenphp`/`queue`/`scheduler`, and no
Caddyfile is rendered. That is 48 MB of binary nothing could start. The bundle
went from 200 MB to 153 MB.

## No php.ini was ever written

`render_config` returned early on Windows. FrankenPHP needs no Caddyfile and no
fpm pool, which is why — but it does need a `php.ini`, and without one PHP
keeps its compiled-in `extension_dir` of `C:\php\ext`, loads no dynamic
extension, and fails every request that touches the database with "could not
find driver". Which is every request: session, cache and queue are all SQLite.

It is written at start, into the run directory, with the install's real paths,
and `spawn` points PHP at it with `PHPRC`. Not bundled, because an ini written
at build time carries the build machine's directories — the file that made this
work locally had a developer's home directory in it — and the install directory
is not writable anyway.

`PHPRC` is set on Windows only. POSIX ships a static PHP with its extensions
compiled in and needs no ini; pointing it at a file that is never written would
change a platform that already works.

## Evidence

Measured on Windows, against the staged runtime, with no hand-written file
anywhere:

```
no PHPRC (what shipped)        ini: NONE   pdo_sqlite: false
PHPRC -> generated ini         ini: found  pdo_sqlite: true
frankenphp php-cli, same ini   ini: found  pdo_sqlite: true
```

All eleven required extensions load. Serving the app through
`frankenphp php-server`, exactly as the supervisor spawns it:

```
GET /       302 -> /login
GET /login  200, 5641 bytes, <title>Laravel</title>
"could not find driver" in body: no
```

`package-runtime.sh` run for Windows reports `all required extension DLLs
present` and `frankenphp.exe and php.exe both start`, and refuses a POSIX run
with no php binary argument.

## Still broken

- **The Laravel app is not in the bundle.** The installer carries the runtime
  and nothing to serve: no `artisan`, no `app/`, no `vendor/`. `resolve_layout`
  falls back to the resource directory, which does not contain them, so a
  packaged Server app still needs `SOUNDCHEX_APP_DIR` pointed at a checkout.
  This is not new and not Windows-specific — the bundle has never included the
  application on any platform — but it is the next thing in the way, and this
  change does not fix it.
- **macOS and Linux are unverified by me.** Every change is either inside a
  Windows-only branch or leaves the POSIX path byte-identical, and the resource
  mapping produces the same destination paths it did before. But I ran none of
  it on macOS or Linux; the new `app` job is what will say.
- **The supervisor still does not reap its children.** Closing the app leaves
  `frankenphp.exe` and both `php.exe` workers holding the port. Unchanged here.
- **`resolve_layout`'s comment still contradicts its code** about where `app_dir`
  resolves in dev.
