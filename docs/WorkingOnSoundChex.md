# Working on SoundChex

What this project has taught, mostly the hard way. Written after a long session
of fixing things on a real device, where several confident diagnoses turned out
to be wrong and the tests that were supposed to catch them did not.

Read [AGENTS.md](../AGENTS.md) first — that is the architecture and the
workflow. This is about how to work here without repeating the same mistakes.

---

## The database

**Never run a schema or seed command without asking.** `php artisan
migrate:fresh` destroyed the real development library — 1,458 media items, along
with play history, watchlists and playlists. The media survived because the
database is only a catalogue, but everything derived was gone and had to be
rebuilt by rescanning.

- `migrate:fresh`, `db:wipe` and `migrate:rollback` are never the right answer
  on the dev database. Write a new migration instead.
- The e2e database exists to be destroyed. Use it.
- Back up first when a migration is genuinely needed: `php artisan db:backup`
  takes a consistent, compressed snapshot in a few seconds.

**Never name the database file by hand.** `database_path('database.sqlite')`
reads like the obvious way to reach it and is a hardcoded path that ignores
configuration entirely. Code written that way ran under test — where the
connection is `:memory:` — and replaced the real library's database with the
gzipped HTML the test was feeding it.

```php
// Wrong. Ignores the connection, so :memory: writes to the real file anyway.
$path = database_path('database.sqlite');

// Right. Null under :memory:, which is the answer that refuses.
$path = config('database.connections.' . config('database.default') . '.database');
```

The suite running on `:memory:` did not save it, because the code never asked
the connection where to write. A hardcoded path is not a detail when the thing
at the end of it is the only copy.

**Restoring is three steps, not one.** Copying a backup over
`database/database.sqlite` leaves SQLite still reporting `database disk image is
malformed`, because the `-wal` and `-shm` files beside it belong to the database
that was just replaced and no longer match:

```bash
rm -f database/database.sqlite-wal database/database.sqlite-shm
gunzip -c storage/backups/soundchex-DATE.sqlite.gz > database/database.sqlite
php artisan migrate --force    # the backup predates anything since
```

Nothing warns about the stale files, and the error is identical to the one a
genuinely corrupt file gives — so it reads as "the backup is bad too" when the
backup is fine.

---

## Testing

**A test that passes whether or not the fix is present is worse than no test.**
It reads as coverage and provides none. This happened repeatedly and the pattern
is always the same: the test asserts something true for an unrelated reason.

**Sabotage-verify everything.** Break the fix, run the test, watch it fail, put
the fix back. Three separate tests in this session passed with the fix removed:

- A path-traversal test where `.env` did not exist at the traversed path, so
  `is_file()` refused it regardless of the guard.
- A service-worker eviction test that planted an arbitrary cache key, which
  activate deletes whether or not the version is stamped correctly.
- A route-ranking test that awaited every measurement, so it passed against
  first-to-answer and best-of-all alike.

**Assert on the mechanism, not a symptom that has other causes.** "The page
renders" is true for many reasons. "This pattern matches this URL" is true for
one.

**Parsing is not working.** A `return` at module top level parsed fine and killed
the entire connect screen at runtime — no autofill, no buttons, nothing. `node
--check` said it was fine. Always load the page and use it.

**Playwright cannot see everything.** Route interception does not reach
service-worker fetches or `no-cors` requests, and the browser reports a
zero safe-area inset at every viewport — so a notched-phone layout bug is
invisible in tests. Verify those on the device.

---

## Diagnosing on a device

**Instrument before theorising.** Four rebuilds went into guessing at a Face ID
failure. The detailed error, once obtained, ruled out every hypothesis in one
line. Ship the diagnostic first.

**The server log is often the fastest answer.** The music page failure was
solved by `~/Library/Logs/SoundChex/serve.log` showing 6,958 artwork requests in
an afternoon, peaking at 1,146 in a minute, while books and films answered in
0.07ms. No amount of reading the client would have found that as quickly.

**Check you are reading the live log.** Two hours went into a stale
`storage/logs/serve.log` while the launchd agent wrote to `~/Library/Logs/`.

**A report that dies with the page reports nothing.** Diagnostics need
`keepalive: true` and must send immediately rather than on a timeout — every
event worth reporting describes a page being torn down.

---

## The platform

**macOS gates `~/Documents` behind TCC.** A launchd agent told to write there is
dropped with `EX_CONFIG` before its program runs, and writes nothing — so the
log that would explain the failure is the thing that caused it. Service logs go
to `~/Library/Logs/`.

**Tauri capability `remote.urls` are URLPattern, not shell globs.**
`http://192.168.*.*:*` compiles without error and matches nothing. Test patterns
against real URLs before trusting them.

**Tauri scopes plugin access to what the shell serves.** Pages loaded from the
Laravel server are a remote origin, and plugin calls from them are refused
unless a remote scope covers them.

**iOS forbids an app installing its own binary.** The updater is desktop-only.
It matters less than it sounds: nearly everything here is served, so a deploy
reaches a phone without a rebuild.

**Free provisioning profiles last seven days.** The app stops opening with "no
longer available" and needs a rebuild. A paid account gives a year, but the
device must be registered to the paid team — the free registration does not
carry over.

**Never hardcode a LAN address.** This machine's changed four times in one day.
Tailscale addresses are stable; LAN addresses are not.

---

## Performance

**Measure before optimising, and measure the real library.** The test library
has two music items and the real one has 1,356. Every music page problem this
session was invisible until measured against the real thing.

**A relayed route is not merely slower.** Tailscale Funnel measured 1,332ms
against 18ms direct — the same server, from the same room, via Los Angeles.
Rank relays below direct routes rather than trusting a latency comparison,
because a relay that gets one lucky measurement will hold the connection.

**Draw a screenful, not a library.** The offline shell rendered every item in
the mirror — 1,356 rows, each with an image — as a *placeholder* shown for a
moment before the real page arrived.

**`stale-while-revalidate` still makes the request.** For content-addressed
files there is nothing to revalidate; serve from cache alone.

---

## Changing this codebase

**Look for the existing implementation first.** Several bugs were things already
built and left unwired: `[data-download-batch]` buttons that nothing bound,
`[data-download]` icons the same, a `music-nav` the offline shell rebuilt without
its buttons. Grep for the attribute before writing a handler for it.

**Blade and the offline shell must agree.** Any row rendered in Blade is also
rendered in `resources/js/library/render.js`. A change to one that misses the
other produces a UI that differs depending on whether the server answered.

**Beware duplicate CSS rules.** A correct `#now-playing` rule was overridden by
a second one added 120 lines below with a smaller offset. Same specificity,
later wins. Grep for the selector before adding a rule for it.

**Tailwind utilities lose to this project's stylesheet.** `md:hidden` did not
hide the mobile tab bar because `.mobile-tabs { display: grid }` came later in
the cascade. Scope the breakpoint in the stylesheet rather than fighting it.

**`vite build` is not the build.** The real one is
`npm run build` — `vite build && node scripts/embed-offline-shell.mjs`. Running
vite alone regenerates the asset manifest but leaves the service worker stamped
with the *previous* build's digest, so `sw.js` advertises a cache version that
no longer matches what it would cache. It fails as one puzzling
`service-worker.spec.js` failure comparing two hex strings, several steps away
from the rebuild that caused it, and every other test still passes.

**Two correct guards can deadlock.** A finished batch download kept spinning
because `runBatch()` marks rows `downloading` and the repainter skips buttons
reading `downloading` — the first so a long batch does not look idle, the
second so a repaint does not reset a live transfer. Each is right, each has a
real bug behind it, and together they mean the finished state is never written.
Neither looks wrong at its own call site. When two pieces of defensive code
guard the same field, check what happens when both fire.

**When everything reports success and the UI disagrees, the bug is in who may
write.** The download queue emitted `started → finished → idle`, the fetch
returned 200 with every byte, and storage had 2.1 GB free. Tracing the download
harder would have found nothing; the question worth asking was which code path
was allowed to set the state.

**One run per side is not a bisect when the test is flaky.** A download test
failed, reverting a suspect change made it pass, and that looked like proof. It
was not — the original code failed 4 runs in 5, so a single green run was noise.
Before concluding a change caused a failure, run both sides several times; if
the answer varies, the test is the problem and the bisect never started.

**A test can fail for doing exactly what it should.** The same test asserted
that a download queue survived a reload. It did. But `resumeDownloads()` runs on
load and immediately picks the queue back up, and the fixture files are tiny, so
the queue had legitimately drained before the assertion ran. The test was
watching correct behaviour and reporting a regression. When a test fails, check
what the code is *supposed* to do at that moment before assuming it broke.

**Consistent wrong answers are not a race.** The first theory was a timing race
against a 4-second timer. Logging the actual value showed the same `["803"]`
every run — races vary, and that consistency is what pointed at the real cause.
Log the value before theorising about the clock.

**A green test can be guarding nothing, and it looks identical to one that
works.** Two tests written this session passed with their fix reverted: one
asserted a cover path appeared somewhere in the page, but the same item also
rendered in a rail below; scoped to the right element it still passed, because
two rows created in the same second tie on `latest()` and SQLite happened to
break the tie correctly. Revert the fix and watch the test fail — that is the
only evidence the test is real.

**Editing files with Python string replacement is fragile.** Several edits
landed in the wrong place or removed a brace, twice producing a file that would
not parse. Assert the text you expect to find before replacing it, and check the
result parses.

---

## Communicating

**Say what was verified and what was assumed.** Several claims this session were
wrong: that the app shipped without a frontend (it did not — Tauri compresses
embedded assets and a plaintext grep cannot see them), and that a device build
could not be run from here (it could, and had been).

**A user's report is evidence.** "iPhone 3000" was dismissed as not a real
device; it was the device's name, and confirmed the hardware model. "Is it still
the connect screen?" was right when the tests said otherwise.

**Report failures plainly.** If a test is failing, say which and why, rather
than reporting a pass count that excludes it.
