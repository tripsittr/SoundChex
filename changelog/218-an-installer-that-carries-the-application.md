# An installer that carries the application

The Server app shipped a PHP runtime and nothing to serve. Installing it gave
you `runtime/`, `soundchex.exe` and `uninstall.exe` — no `artisan`, no
`vendor/`, no `public/`. It only ever worked where a checkout already sat on
the machine, which is why the one install path that existed was a macOS script
copying a locally built `.app` next to a developer's own repository.

This bundles the application and provisions it on first run, so an installed
Server app serves a library on a machine that has never seen this repository.

## The application ships as one archive

`vendor/` alone is 32,693 files, and Tauri writes one installer entry per
resource file. So `scripts/package-app-payload.php` packs the application into
`app-payload.zip` — 33,379 files, 80 MB — bundled as a single resource, with
`app-payload.id` (a hash of the finished archive) beside it.

PHP does the packing because this project cannot be built without PHP, and
`ZipArchive` behaves the same on all three platforms — unlike the shell, where
the Windows runner has no `zip` and `Compress-Archive` takes minutes over a tree
this size.

The payload is an allowlist, not the tree minus exclusions: a missing file is a
loud error on first run, an unexpected one may be somebody's library. There is
a second, explicit refusal to package any `*.sqlite` on top of that, because
`database/` on a development machine is 33 MB of the maintainer's own media
library.

## It unpacks rather than running in place

An installed program directory is read-only, and Laravel writes to `storage/`,
`bootstrap/cache/`, `.env` and the SQLite file on ordinary requests. So
`provision.rs` unpacks the payload into the app data directory on first start
and runs from there.

That was chosen over pointing the framework at writable paths — `useStoragePath`
and friends — because unpacking leaves the application byte-identical to a
checkout. Nothing about how it boots differs between a packaged install and a
developer's machine, and this change therefore touches no PHP beyond a one-line
`.env.example` fix.

First run also creates the directories Laravel writes to, writes an `.env` from
the shipped example with this install's real database path, generates
`APP_KEY`, creates and migrates the database, and links `public/storage`. Later
starts compare one hash and stop.

`public/storage` uses a directory junction on Windows rather than
`storage:link`. A symbolic link there needs administrator rights or developer
mode, which a library on someone's desktop cannot assume; a junction needs
neither and is followed by both Explorer and PHP.

`SOUNDCHEX_APP_DIR` still wins over the payload, deliberately. A machine already
serving a library out of a checkout keeps doing so after an upgrade instead of
silently starting a second, empty one.

## Two bugs found by running it

**`.env.example` could not be parsed.** `ARR_CONFIG_ROOT` was an unquoted path
containing spaces (`Application Support`), and dotenv rejects the whole file for
one bad line — "The environment file is invalid!", naming nothing. Every fresh
install died at `key:generate`, including `install-headless.sh`, which copies
the same file and has presumably been broken for as long as that line has
existed. Now quoted, with `EnvExampleTest` covering both the parse and the
shape, and both cases verified to fail without the fix.

**The generated `.env` could not be parsed either, by my own code.** The first
version quoted the Windows database path with double quotes and left the
backslashes raw. Dotenv processes escape sequences inside double quotes, so
`"C:\Temp\app\database.sqlite"` fails on `\T` exactly as hard as an unquoted
path fails on a space. Backslashes are now escaped. Single quotes would also
work but cannot hold an apostrophe, and people called O'Brien have home
directories. The four candidate encodings were measured against the parser
rather than reasoned about.

The unit test for this had asserted the broken form — it was written to match
the code instead of the parser, and passed while every install failed. It now
asserts what dotenv accepts.

## Evidence

The installer was built, installed silently to an empty directory, and
provisioned exactly as `provision::ensure` sequences it, on a machine with the
checkout nowhere in the picture:

```
unpacked 33,379 files
artisan / vendor / public / config / routes   all present
database/*.sqlite shipped?                    no
key:generate   INFO Application key set successfully.
migrate        all migrations DONE
public/storage junction created
GET /          302 -> /login
GET /login     200, 5,623 bytes
GET /register  200, 6,229 bytes
```

Installer 112 MB, installed 238 MB. Eight Rust tests cover unpacking (including
an archive entry that tries to escape the app directory), `.env` writing and the
no-payload case; two PHP tests cover `.env.example`.

## The one that actually stopped it

With everything above in place the server still would not start, and the
reason was a single prefix.

`AppHandle::path().resource_dir()` returns a Windows *verbatim* path on this
platform, \\?\C:\... — correct for Rust's own file APIs, and wrong the
moment the string is handed to another program. It went straight into the
generated `php.ini`:

```ini
extension_dir = "\\?\C:\Temp\scx3\runtime\bin\ext"
```

PHP does not understand that prefix, and its ini parser ate one backslash on
the way in, so it looked for \?\C:\..., found nothing, and loaded **no
dynamic extension at all**. `migrate` then failed with "could not find driver"
— the same symptom as having no ini, arrived at from the opposite direction.

Every hand-run verification of this had passed, because every one of them
wrote the ini itself with a plain path. The code could never have worked.

Paths are now normalised where the resource directory is first resolved, and
ini values are written with forward slashes, which Windows PHP accepts and
which no ini escaping can corrupt. Four tests cover it, UNC shares included.

`optimize:clear` also no longer runs on a first install: there is nothing to
clear and no database yet, so it logged a failure that read like the cause of
everything after it.

## Confirmed running

Installed from the built `.exe`, launched, and watched:

```
t+ 5s  files=2,959
t+15s  files=16,834
t+20s  files=17,163   database created (598 KB, migrations run)
t+25s  port 8000 listening

frankenphp php-server --root public --listen :8000
artisan queue:work / schedule:work / schedule:run / network:probe

GET http://127.0.0.1:8000/      302 -> /login
GET http://192.168.1.156:8000/  302 -> /login   (from the LAN address)
GET /register                   200
```

Serving the unpacked copy in the app data directory, with `SOUNDCHEX_APP_DIR`
unset -- so nothing on the machine but the installer was involved.

## Still broken

- **First start takes about a minute** — 33,379 files is 57 seconds of
  unpacking, and `server_start` is synchronous, so the button says "Starting…"
  and the window looks hung. It is the first thing a new user sees and it should
  report progress.
- **The Rust orchestration has not been driven end to end.** `server_start` is
  invoked by a click in `server.html`, which cannot be scripted here. The
  sequence above reproduces exactly what `ensure` does, and the parts are unit
  tested, but nobody has watched the button do it.
- **macOS and Linux are unverified.** They now bundle the payload too, so their
  installers change shape as well. A packaged install keeps its library in the
  app data directory, so anyone whose library lives in a checkout should set
  `SOUNDCHEX_APP_DIR` and keep it.
- **Upgrades leave deleted files behind.** Unpacking overwrites what the archive
  contains and removes nothing, which is what protects `storage/` and the
  database; a file deleted upstream lingers.
- **`server/supervisor/windows/install-services.ps1` is stale** — it still
  describes Windows as having no HTTP front and awaiting php-cgi packaging,
  superseded by FrankenPHP.
