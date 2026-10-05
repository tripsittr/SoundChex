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
## What the worker is doing, and a logo that was never there

**A modal behind every row of Background work.** The table could say 5,877 jobs
were waiting, which is the question it provokes rather than an answer to it.
Clicking the job's name now opens what the worker has in hand — named, not
numbered: *Men's Needs*, job #11626, attempt 1, started two seconds ago — what
is next, any failures for that kind, and the tail of the log filtered to the
items involved.

The log was the point. It is where this app says what happened, and reading it
meant opening a 7 MB file on the server, which is not a reasonable thing to ask
of someone running a media server on their own machine. `WorkerLog` reads the
last 256 KB and parses it, never the whole file: it grows without bound, and
loading it to show forty lines would be a denial of service against ourselves.
Stack-trace lines are folded into the entry above them, because one exception is
dozens of continuation lines and showing each as a row buries everything else.
An entry that names no item is always kept even when filtering — a failure that
never got as far as naming one is exactly what someone opening this needs.

It answered a real question within a minute of existing. Every music item was
logging *"Metadata sources skipped themselves — AcoustId, Spotify, Deezer. A
missing API key is the usual cause."* Only `tmdb_api_key` is set, which is why
films resolve cleanly and music comes back `fuzzy` and lands in review. Music
enrichment is working — MusicBrainz, iTunes and the file's own tags need no
credentials — but AcoustID is the one that fingerprints the audio, and without
it nothing gets an exact match.

**The table got cheaper at the same time.** `pending()` loaded every row's
payload to group them — 2.6 MB at five thousand jobs — and the widget polls it
every ten seconds. Counted in SQL now: 47 ms to 17 ms on the real queue. Guarded
by driver, because `json_extract` is sqlite and MySQL and Postgres spells it
differently; an unusual database is now slow rather than broken.

**The admin panel's logo was a broken image**, and had been in every packaged
install. Both brand logos pointed at `asset('storage/soundchex_logo_dark.png')`
— inside `storage/app/public`, which is the user's own uploaded content, which
provisioning deliberately never ships. The files were not in the repository
either, so they only ever existed on a machine where someone had put them there
by hand. They resolve through Vite now, like every other logo in the app, and
Vite throws on a name that is not in the manifest rather than quietly serving a
404.
## Jobs a dead worker was still holding

Raising `retry_after` to stop the false `MaxAttemptsExceeded` failures had a
cost that only showed up while verifying the install. A reserved row does not
record *which* worker holds it, so a worker killed mid-job leaves its job
reserved and indistinguishable from work in progress — and nothing reconsiders
it until `retry_after`. That used to be ninety seconds. It is now six hours,
deliberately, because it has to exceed the longest a job may run.

So every restart cost whatever was in flight six hours of doing nothing. Visible
on this install: job 11889 reserved at 15:17:30, the worker that held it killed
by the installer, and the worker that replaced it starting at 15:18:37 with no
way to tell the difference.

A worker starting is the one moment it is provably safe to free them, because
the supervisor runs exactly one — nothing can be holding anything while it is
starting. Attempt counts are left untouched, so a job that genuinely kills its
worker still runs out of tries rather than cycling for ever, and the release is
logged with how many, because those jobs are about to run a second time.

Behind `queue.release_reservations_on_worker_start`, which a deployment with
more than one worker must turn off: a starting worker would otherwise free a job
its sibling is part-way through and the job would run twice. The guard reads the
connection off the event rather than the application default, because those two
differ and the event is the one that is right.
## A reviewed file keeps being searched

A decision used to live on the file. `duplicate_status` said "keeping both" and
the scanner skipped that row for ever after — it had no choice, because the row
records which item it was compared against but nothing about what was decided.
So "we settled A against B" had to be read as "leave A alone", and anything
added afterwards was never compared against A at all. Decide two albums are both
worth keeping, rip a third copy next month, and nothing notices.

`duplicate_decisions` records the pair instead, which separates the two: the
question that was answered stays answered, and every other question is still
asked. Stored lowest id first, because which copy the scanner calls "the
original" depends on where each file sits at the time and changes when one is
filed into the library — a decision that moved with it would be no decision.

The migration carries over the rulings already made. Without that, the first
sweep after upgrading would re-ask every question the user has ever answered,
which is the failure the old behaviour existed to prevent and a worse one than
the gap being closed.

Merged rows stay out of the sweep. Merging repoints the redundant row at the
surviving file, so such a row shares a path — and bytes — with its original;
searching it would match a third copy and drag a settled row back into review
for a file it does not have its own copy of.

Both halves are tested, and checked by reinstating the old skip: exactly the two
tests that should fail did.

## Asking the metadata providers less

"Can the workers go faster, or can we run more of them?" More workers is the
wrong lever, and the numbers say why. The worker spends two seconds of CPU per
minute — it is waiting on HTTP, not computing. And the ceiling is not ours:
MusicBrainz rate-limits anonymous clients to roughly one request a second, which
this app respects by accident rather than by design (there is a comment about
the policy and no throttle). Three workers would spend the same allowance three
times faster and risk being blocked.

What the queue actually holds is repetition. Measured on the real library
mid-enrichment: 5,454 queued music lookups, 2,132 of them distinct. 61% repeats.
`a-punk|vampire weekend` and `sun|two door cinema club` were each queued twelve
times, and neither MusicBrainz nor iTunes responses were cached, so all twelve
asked again.

`LookupCache` sits in front of both, keyed on the query with the parameters
sorted so the same question written two ways is one key. Seven days: long enough
to cover a whole library sweep, short enough that a re-fetch next week sees a
record the provider has since corrected.

An empty answer is cached, and that is most of the win — the tracks nothing can
identify are exactly the ones that repeat, and asking twelve times gets the same
nothing. A failed request is never cached: one rate-limited minute would
otherwise poison a week of lookups for every track in it.
## Duplicate films and episodes, which were never looked for

"Literally they have almost identical file names or names in the database. How
can we not discover those?" — a fair question with an unflattering answer: for
film and television the only check was an exact byte hash. Music had a whole
content pass — ISRC, MusicBrainz id, fingerprint, then tags — and video had
nothing at all. A second copy of a film is never byte-identical: it is a
different rip, a different release, or a download that stopped early. So the
obvious cases were structurally invisible.

Two from the real library, now the test fixtures:

- **War Dogs**, twice. Both resolved to TMDB 308266. One file 1.8 GB, the other
  18 MB — a stub that never finished downloading. Different bytes, so nothing
  compared them.
- **Bart of Darkness** and **Lisa's Rival**, twice each, same series, same
  season, same episode number, the file names differing by a `(2)`.

Films match on TMDB's id first, which identifies the work and is as strong a
signal as an ISRC. Failing that, title *and* year together — required, not
preferred, because there are two films called "The Mummy" and they are not
copies of each other.

Episodes match on season and episode number within the same series. Numbering
beats title: series reuse titles across seasons and numbering does not. Scoped
to the series — by parent row, or the series' own id — because every series has
an S01E01 and matching on numbering alone would pair all of them.

Neither is ever deleted automatically, like the music content pass. The files
genuinely differ, and choosing between 1.8 GB and 18 MB is obvious to a person
and not to this code.

## The server was unreachable, and looked broken instead

"Is it set up for me to connect? Tailscale maybe?" It was not, and the way it
failed was the problem: the server bound `:8000`, answered on loopback, and was
unreachable from every other device on the tailnet. That is indistinguishable
from a broken server.

Windows blocks unsolicited inbound connections for which no rule exists, and
nothing ever created one. (A check from the machine itself proves nothing here —
loopback is not filtered. That is how this was missed in the first place.)

The installer now adds the rule, scoped rather than open:

- `100.64.0.0/10` — Tailscale's address range, so the user's own devices reach
  it from anywhere and nothing else can route to it.
- `LocalSubnet` — the home network, so a laptop in the same house needs no
  tunnel.

Deliberately not the public internet: a machine on a café network must not start
serving someone's film collection to the room. Widening that is a decision for
the person who owns the library to take knowingly, not one an installer takes
for them. The uninstaller removes the rule — a firewall hole outliving the thing
it was for is how a machine ends up with holes nobody can account for.

Under `bundle.windows.nsis`, so macOS and Linux never see it.

## A test that failed hours after the run that caused it

`test_it_sweeps_a_session_nothing_has_touched` started failing with "2 is not
1", and only in a full run. It passed alone, and passed in a full run an hour
later.

`test_it_leaves_a_session_that_is_still_being_written` deliberately creates an
HLS session that must *not* be swept, asserts it survives, and left it on real
storage — these tests write to `storage/app/private/hls`, not a faked disk. Its
newest segment is "just written", so for the next two hours the next run agrees
it is live. After that the segment is older than the sweep threshold, the next
run removes it *and counts it*, and a different test fails for it.

So the failure needed two runs more than two hours apart, which is why it looked
like it arrived from nowhere. The session directories are tracked and removed in
`tearDown` now; the file passes twice in a row and leaves nothing behind.
## Merging a duplicate: two bugs, one of which was destructive

Detection for film and television found the pairs on the real library — War Dogs
on TMDB id, two Simpsons episodes on season and episode. Merging them did
nothing and reported "a file was missing or the contents no longer match", about
files that were all present. Both halves of that were wrong.

**`decideKeeper()` had no idea what "better" means for a video.** It judges on
bitrate, sample rate and tag completeness, every one of them read off
`musicMetadata` — which a film does not have. So every video pair came out a
tie, and the bulk action broke ties by keeping the higher row id. On this
library that would have:

| Pair | Would have kept | Would have deleted |
| --- | --- | --- |
| Lisa's Rival | 17 MB | **43 MB** |
| Bart of Darkness | 2 MB | **41 MB** |
| War Dogs | 1.77 GB | 18 MB |

Two of three inverted, deleting the good copy to keep a stub. War Dogs came out
right by luck, because the large file happened to be catalogued second.

Now the larger file wins, on a 10% margin so two rips differing by container
overhead stay a tie and go to a person. Ties are no longer broken by row id for
video even when the caller asks: there is no quality signal left to break one
with, and a coin toss that deletes a file is not a decision. A copy that is gone
loses to one that is present. Music is untouched and still decides the way it
did.

**`unlink()` will not delete a read-only file on Windows.** 285 of the 2,843
files in this library carry that attribute — `War Dogs (2016).mkv` is
`ReadOnly, Archive, SparseFile`, so it arrived that way from a copy off another
machine. Every merge touching one failed, the row stayed `pending`, and the next
sweep flagged it again. That is the whole of "I merged it and they came back",
and almost certainly the earlier "8 duplicates that wouldn't merge" too.

The delete now clears the attribute and retries, and logs when it does. The two
bugs cancelled each other on this library: the files the keeper logic would have
destroyed were read-only, so the delete failed and nothing was lost. The bug
that was frustrating is the bug that saved the library.

**And the failure was silent.** Nothing was logged, and one message covered
three different outcomes while naming a cause that was not any of them.
"Skipped" is now three: merged, too close to call — open one and choose — and
could not be deleted, with the real reason in the log.

Checked by reverting both fixes: four tests fail, including `'newer'` where
`'file_size'` belongs, which is the row-id coin toss, and the read-only merge
returning false.
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
- **Enrichment is still bounded by one request a second.** Caching removes the
  repeats, but the distinct lookups remain, and nothing in the app enforces
  MusicBrainz's rate limit — it is respected by happening to be slow enough.
  Running a second worker would breach it, so concurrency needs a shared
  client-side throttle first, and the stranded-job release turned off.
- **285 library files are read-only.** Deleting through them works now, but
  anything else that writes to a file in place — retagging, cover embedding,
  transcoding over the original — may still fail on those 285. Only the
  duplicate delete path has been taught to clear the attribute.
- **Only TMDB has a key.** AcoustID, Spotify and Deezer are unset, so every
  music item is matched by tags, MusicBrainz and iTunes alone and comes back
  `fuzzy`. AcoustID is the one that fingerprints the audio; until it has a key,
  the review queue will keep filling with matches that are probably right.
- **`server/supervisor/windows/install-services.ps1` is stale** — it still
  describes Windows as having no HTTP front and awaiting php-cgi packaging,
  superseded by FrankenPHP.
