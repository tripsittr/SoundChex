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

## The admin panel was redirecting to a port with no TLS on it

With the server running, opening it reported an insecure connection. `/admin`
answered `302 https://127.0.0.1:8000/admin/login` — https, against a bundled
server that speaks only http, so the handshake failed and the panel could not be
reached at all. The app navigates straight there once the server is up, so it is
the first thing anyone sees.

`AppServiceProvider` forces https when `APP_ENV=production`, which is correct
and deliberate: this server can be put on the public internet through a tunnel.
`SetAppUrl` is what keeps that honest, resetting the scheme and host to whatever
the request actually arrived on. But it is prepended to the `web` and `api`
groups, and Filament's panel declares its own middleware stack — so `/admin`
never got it, and the forced https stood unopposed.

Provisioning setting `APP_ENV=production` is what exposed this. A checkout runs
as `local`, so the forcing never fired and the gap was invisible.

The panel now runs `SetAppUrl` first, like every other route. Two tests: an http
request must not be redirected to https, and a request forwarded as https by a
relay must keep it — the tunnelled case the forcing exists for. The first fails
without the fix, with exactly the observed redirect.

Verified on the installed app: `/admin` and the LAN address both redirect on
http now, and `/admin/login` renders. That run was also an upgrade — a new
payload id, so every file was rewritten — and the database survived it, grown
from 598 KB to 647 KB, with `APP_KEY` intact.

## A fresh install opens on registration, not a login form

The first thing a new install showed was a login page. There are no credentials
to type on a server with no accounts, and nothing on the page said so — the only
way forward was knowing to type `/register` by hand.

Until an account exists, every entrance is registration now:

- `/` sends a guest to `register` rather than `login`.
- `/login` redirects to `register`, so the dead end cannot be reached at all.
- `/soundchex.json` reports `setup_required`, and the desktop app opens there
  instead of `/admin` — which would have bounced to a Filament login form with
  the same problem.
- The first account lands on **profiles** after registering, not the media
  centre. A brand-new library has no media and no profile; profiles is the one
  screen with something to do. Everyone joining an existing library still goes
  straight to the library.

`setup_required` is sent to loopback callers only. The endpoint is public and
sends `Access-Control-Allow-Origin: *`, and "nobody owns this library yet" is
exactly what a network scan would like to find, since the first account is the
one that gets the keys.

Four existing tests encoded the old behaviour and were updated rather than
deleted: two needed an account to exist before a login page means anything, one
now covers somebody joining an existing library, and root's test asserts both
states instead of the one a seeded database happened to be in.

Verified on the installed app by screenshot: it opens on **Create your account**.

## Television, duplicates in the review queue, and cover refetching

**Episodes were loose on the shelf.** The Watch page listed every row of type
Show, so 253 Simpsons episodes sat beside the three actual programmes. The
hierarchy was already there — a series row carrying no file, with every episode
parented to it — just never used. Browsing now lists series only, and a series
page groups its episodes by season. Season and episode numbers are read back out
of the filename by `EpisodeParser` rather than stored, and an episode whose name
carries no marker lands under "Other" instead of disappearing. `continueWatching`
still surfaces the individual episode, because it builds its own query.

**Movie enrichment did nothing**, and said why in every report:
`{"name":"TMDB","outcome":"skipped_no_key"}`. With a key configured it still
missed, because the title it searches carries the release's edition wording —
"The Goonies 30Th Anniversary Edition 1985" is no film. TMDB now retries with
that wording removed, which also rescues rows already catalogued, and without
rewriting anyone's title: only the search term is cleaned. Whole phrases only,
never bare words — "Special", "Final" and "Ultimate" are all real film titles and
the stripping runs to the end of the string, so one wrong match would lose the
title entirely.

**Duplicates never reached the review queue.** A pending duplicate is a decision
nobody has taken — the detector says two files are the same and asks which to
keep — but it showed only on the Duplicates screen while "Needs review" reported
nothing to do. The tab now includes duplicates that are `Pending` or `Kept`.
Not `Merged`: those are settled, and there are thousands.

**Two cover buttons**, on every media list page. *Refetch missing covers* is the
everyday one, badged with the count: an item that never got a cover is never
asked about again on its own. *Refetch all covers* is for artwork that is present
but wrong, and is confirmed because it is heavy. Both re-run enrichment rather
than calling an artwork fetcher, because enrichment is what sets
`cover_image_url` for every type; `RefetchCoversJob` shows the cost of a separate
path, reading `musicMetadata` and requiring `needs_cover_review` so that it
cannot serve a film at all.

Verified against the real library: The Goonies resolved to TMDB 9340, 1985,
114 minutes, with a cover.

## The queue on the dashboard, and maintenance on every type

**Background work is now visible.** Every heavy operation queues — enrichment,
cover fetching, duplicate hashing, transcoding — and starting one gave a
notification and then silence. A *Background work* table reads the queue tables
directly: pending jobs grouped by kind, how many wait, how many a worker holds
right now, the age of the oldest, and how many have already been attempted.
Failures group by reason, because twenty rows of "database is locked" is one
problem and twenty identical rows hide whatever else failed once. There is a
retry button: the worker runs with `--tries=1`, so a single lock kills a job for
good. "Nothing is being worked on" is phrased as a question — nothing reserved
is either a dead worker or a worker between jobs, and the widget cannot tell
which. The recurring schedule sits underneath, with the heartbeat
`routes/console.php` writes, so "next run" is a prediction and not an intention.

**Maintenance on every media type**, in one dropdown: refetch missing covers,
refetch all covers, re-enrich what needs review, re-enrich everything, and
search for duplicates. Only music had a button before, re-enrichment had none,
and the duplicate search always swept the whole library. The type comes from
the resource's own `mediaType()` — the same declaration that scopes its query,
so the two cannot disagree.

**The dashboard would not open.** The widget shipped with a parse error, and the
page reported only "There was an error while attempting to load this page." The
cause was one line of Blade:

```blade
{{ number_format($totalPending) }} waiting@if ($totalFailed > 0), … @endif.
```

Blade matches a directive only at a non-word boundary, so the `@if` glued to
`waiting` was left as literal text while its `@endif` compiled anyway. The
generated PHP carried a stray `endif`, and nothing complained until the page was
opened. The count is interpolated now instead.

`view:cache` does not catch this. It runs the compiler and writes the result,
and invalid PHP is written out as happily as valid — which is how it shipped
twice. `tests/Feature/BladeViewsParseTest.php` now compiles every template and
runs `php -l` over the output: 87 views, one process each, and it named the one
broken file immediately.

## Three failed jobs, three different causes

The dashboard that now shows failures immediately showed three, and they turned
out to share almost nothing.

**`Typed property DetectDuplicatesJob::$type must not be accessed before
initialization`.** The job gained an optional `type` last week. A job queued
*before* that unserialises with the property simply absent, and a typed property
with no value throws when read rather than reading as null. The queue table is
in the database, and the database is the one thing provisioning deliberately
does not replace — so payloads outlive the code that wrote them, and an upgrade
is exactly when this fires. Read through `?? null` now, which is isset-based and
safe on an uninitialised property.

**`MaxAttemptsExceededException` on a job with `tries = 1`**, which reads as a
contradiction. `retry_after` was 90 seconds and `DetectDuplicatesJob` declares a
timeout of 3600: the queue concluded the running sweep had been abandoned,
handed it to another worker, and the second copy failed on the spot for having
been attempted once already — while the first was still hashing. Seven jobs
declared timeouts above that 90-second line, so no part of this was specific to
duplicates. `retry_after` is 22200 now, above the `--timeout=21900` the
supervisor gives the worker for the jobs with no ceiling of their own:
transcoding, and server-to-server transfers, which legitimately run for hours.

The test for it reads that number out of `src-tauri/src/supervisor.rs` rather
than repeating it, because a number copied into a test asserts only that someone
once copied it correctly. A second test refuses any job whose declared timeout
reaches `retry_after`.

**`database is locked`, repeatedly — and not for the reason it looked like.**
Two queue workers were indeed running, orphaned: a clean quit kills the
children, but on the watcher thread, so force-killing or crashing the app took
the thread with it and the next launch started a second pair against one SQLite
file. They are now assigned to a Windows job object with `KILL_ON_JOB_CLOSE`,
which moves the guarantee into the kernel — the last handle closes when the app
exits by any route and everything in it is terminated. POSIX is untouched;
there the equivalent is a process group, and that stack works. Strays from
before this change are not adopted retroactively, but a fresh launch cannot
create more.

That was not the cause, though. Locks kept failing after the second worker was
gone, so the stack trace was worth reading rather than assuming, and it pointed
inside Laravel:

    DatabaseQueue::pop()
      Connection::transaction()
        markJobAsReserved()
          update "jobs" set reserved_at = ...   <- BUSY

`transaction_mode` was `DEFERRED`, the Laravel skeleton's default. A deferred
transaction takes no lock at BEGIN: the SELECT fixes a read snapshot, and the
UPDATE then asks to become a writer. If anything committed in between, that
snapshot is stale and SQLite refuses the upgrade *at once* — waiting cannot make
an already-taken read valid, so `busy_timeout` is never consulted. That is why
two minutes of configured patience bought nothing, and why this looked
unexplainable: the setting meant to cover it could not apply.

`IMMEDIATE` takes the write lock at BEGIN, before reading. There is no upgrade,
so there is nothing to refuse, and a contended lock finally becomes something
`busy_timeout` can wait out. Write transactions serialise as a result; for one
machine's media server that is the right trade, and a transaction that waits is
better than a job that dies.

`SqliteTransactionModeTest` drives sqlite directly rather than through the
framework, because the framework would only show the setting being passed along.
It reproduces the failure — a deferred reader-then-writer losing its upgrade —
and asserts it fails in under a second with a two-second timeout available,
which is the evidence that `busy_timeout` was never in play. The second test
runs the same sequence as `IMMEDIATE` and watches it finish.
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
