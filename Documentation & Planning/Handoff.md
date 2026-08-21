# Handoff

Written 20 August 2026, at the end of a long session working against a real
iPhone. This is the state of things, what was fixed, what is known to be broken,
and what was tried and abandoned.

Start with [Status.md](Status.md) for what the project is. This is about where
it stands right now.

---

## Current state

**Library:** 1,368 items, 1,363 with artwork. Rebuilt this session after a
`migrate:fresh` destroyed the catalogue — see *Past incidents*.

**Services:** all three launchd agents installed and running (`serve`, `queue`,
`scheduler`). Verified to restart within ~5 seconds of being killed.

**Apps installed:** iOS on "iPhone 3000" (iPhone 16 Pro), and SoundChex Server
on this Mac. The iOS provisioning profile now expires **21 August 2027**.

**Tests:** 252 PHP, ~180 Playwright across desktop, mobile and shell projects.

**Addresses:** LAN (changes with the network), tailnet `100.106.62.120`, and the
Funnel hostname `macbookair.tail7e590c.ts.net`. Clients learn all three from the
server and prefer direct routes.

---

## Known issues

### Outstanding

**Two metadata sources are key-gated and have no key.** Audited 21 August 2026;
all nine registered sources are written, and most need no credential at all:

| Source | Needs a key | Key present |
|---|---|---|
| FileTagger, MusicBrainz, iTunes, Open Library | no | — |
| TMDB (film and TV) | yes | set 21 Aug 2026 |
| AcoustID | yes | **empty** |
| Spotify | yes | **empty** |
| OpenSubtitles | yes | **empty** |

The `musicbrainz_enabled` and `openlibrary_enabled` settings are empty but gate
nothing — those two check only the media type, so they run regardless.

The failure mode is silence: a key-gated source with no key returns
`supports() === false` and is skipped without a word, so an item completes
"enriched" with nothing and no error is raised anywhere. That is how every film
in the library ended up with no year after the database was rebuilt, unnoticed
until the conversion filer refused to file one. Worth a visible warning when a
registered source is skipped for want of a credential.

**Three songs match no provider** — items 1445, 2002 and 2473. They complete
cleanly with `match_confidence = none`; the providers simply have no record.
Not a fault, but they will never gain metadata without a manual match.

**Tailnet key expires 2027-02-09.** The MacBook drops off the tailnet that day
until re-authenticated, and on a phone that looks exactly like the server being
down. Disable key expiry for the machine in the Tailscale admin console. The
server app warns four weeks ahead.

**Updater endpoint is hardcoded** to `macbookair.tail7e590c.ts.net` in
`src-tauri/tauri.conf.json`. If the tailnet name ever changes, desktop apps stop
finding updates, and the only fix is a rebuild — the endpoint is baked in at
build time and cannot be learned at runtime the way server addresses are.

A second endpoint was tried as redundancy and removed: the tailnet IP
`100.106.62.120` serves `301` to `https://` on port 80, and the certificate
covers the MagicDNS name rather than the bare IP, so the HTTPS hop fails TLS
(`curl` exit 35). Any fallback needs a name the certificate actually covers, so
adding one means adding a certificate, not an array entry. Left as a single
documented point of change rather than shipping something that looks like
redundancy and is not.

**The test suite is slow** — `workers: 1` and `fullyParallel: false`, because
tests share one SQLite database and one seeded library. That constraint is real;
the fixable part is the remaining hardcoded `waitForTimeout` calls.

**Windows and Linux client builds are untested.** The config approach carries
over unchanged but neither has been built.

### Fixed 21 August 2026

**The now-playing sheet flake is diagnosed and fixed.** It was a lost-event
race, not test pollution: the sheet's deferred wait for the player used
`{ once: true }` on every call, and the function re-runs on every navigation, so
a second deferral consumed the pending listener while the global bind-guard
could mark the sheet bound anyway. Tapping the bar then followed the link and
left the page. Covered by `tests/js/now-playing-sheet.test.js`, which fails
against the previous code; the Playwright spec ran 70 times clean afterwards.

**Enrichment jobs no longer drop under load.** Twelve had failed as "database is
locked", all within a second of the five-minute scan cron. The scan is not slow —
it imports files, and each import dispatches an enrichment job that then holds a
connection through slow provider calls, up to 60s of Chromaprint fingerprinting
for music. Against a 10s busy timeout they knocked each other over. Raised to
120s; the twelve were retried and all twelve ran.

### Fixed earlier, worth re-testing on device

- Music page failing to load (artwork request flood — see below)
- White flash returning to home during navigation
- Connect screen showing false errors before connecting
- Connect screen entirely dead — a top-level `return` killed the script
- "Nothing saved on this device" while holding downloads
- Download queue losing its place when going offline
- Both navigation bars showing in the desktop app
- Transparent strip above the desktop header

---

## What was fixed, and why it was hard to find

**The music page.** The offline shell pre-renders every destination from the
device's mirror before the server's page arrives. It drew *every* item — 1,356
rows, each with an artwork image — so one tap requested over a thousand files.
The server log showed 6,958 artwork requests in an afternoon, peaking at 1,146
in a minute, while books and films answered in 0.07ms. Now capped at 60 rows,
and artwork is served from cache without background revalidation.

**Routing through Los Angeles.** The app connected via the Funnel hostname,
which relays through Tailscale's infrastructure: 1,332ms against 18ms direct.
Three compounding causes — the client knew only the address it arrived on, the
server was not advertising its tailnet address because `tailscale` was not on a
launchd agent's `PATH`, and the ranking treated a relay as merely another stable
address.

**Services that would not start.** `serve` and `queue` exited with `EX_CONFIG`
while `scheduler` ran on identical configuration. macOS gates `~/Documents`
behind TCC, and a launchd agent told to write its log there is dropped before
its program runs — so the log that would have explained it was the thing causing
it. Logs moved to `~/Library/Logs/SoundChex/`.

**Face ID.** Abandoned. Tauri refuses plugin calls from pages the shell did not
serve, and the media centre is served by Laravel. Four fixes were made chasing
it — wrong global name, `withGlobalTauri` unset, URLPattern syntax, window
scoping — all genuine bugs, none of them the blocker. Removed rather than left
as a setting that never works.

---

## Past incidents

**The database was destroyed.** `migrate:fresh` was run on the development
database to drop a column. It dropped every table: 1,458 media items, play
history, watchlists, playlists, profiles. No WAL file, nothing recoverable.

The media survived — the database is only a catalogue — and was recovered with a
new `library:recover` command that rebuilds rows from already-filed media
without moving a file. Play history and playlists are gone permanently.

Two things came out of it: `db:backup` (compressed, nightly, restore-verified)
and a standing rule that no schema or seed command runs without asking.

**The e2e environment was silently broken.** `.env.e2e` had two variables
concatenated onto one line, so `LOCAL_DISK_ROOT` was read as part of
`TELESCOPE_ENABLED`. Download tests failed with 404s that looked like code bugs.

---

## Tried and abandoned

**Biometric unlock.** See above. `NativeOfflineBridge.md` scopes the native path
if it is ever worth revisiting.

**Baking the server URL into `frontendDist`.** Prototyped to fix the Face ID ACL
problem — it makes the server's pages the app's own frontend, which would have
made them local. Reverted: it bakes the address into the build, so changing
servers means a rebuild, and it removes the connect screen's recovery path.

**Splitting server and client into separate repositories.** Measured instead:
the iOS client is 1.9 MB and the Laravel vendor directory alone is 134 MB, none
of which ships. Two products now come from one config override.

---

## Next steps, in the order I would take them

1. **Re-test on device.** Music, offline mode, the download queue pausing and
   resuming, and the desktop navigation. Several fixes landed together and only
   the music page has been confirmed working.
2. **Group songs under a primary artist.** Reported 21 August 2026:
   `$uicideboy$`, Alan Jackson, America and Avicii each appear as several
   artists because collaborations are stored as one combined string.

   Measured: **233 of 1,034 distinct artists contain a comma**, 8 contain a
   slash. Deriving the primary from the first name before a `,` or `/` would
   take the list from 1,034 to 883 — 151 fragments merged — and resolves all
   four reported cases.

   Two things it must not break, both found by looking:

   - **`Hank Williams, Jr.`** is one artist. A suffix guard for
     Jr/Sr/II/III/IV handles it, and it is already in the library.
   - **`Earth, Wind & Fire`**, `Crosby, Stills & Nash` — bands whose own name
     contains a comma, which the rule would split at the first one. None are in
     the library today, so this is safe now and silently wrong the day one is
     added. Needs an exception list, not just a regex.

   The tags cannot help: **zero of 40 sampled multi-artist files carry an
   `album_artist` tag**, so the primary has to be derived rather than read.
   `FileTagger` prefers `artist` over `album_artist` anyway, which is backwards
   for grouping and worth flipping for the files that do have one.

   Not started — it rewrites metadata across a quarter of the library, so it
   wants a dry run and the user's sign-off before it touches anything.

3. **Fix the flaky now-playing-sheet test.** An intermittently red suite trains
   people to ignore failures.
4. **Device and session tracking** — the user asked for a view of which devices
   are online and what they are doing. `device_reports` is a starting point;
   this needs a sessions table and heartbeats.
5. **APNs push.** The paid account makes it possible and the payload already
   exists — `notifications.js` sends what push would carry. Needs a certificate,
   token registration, and a server-side sender.
6. **Speed up the test suite.**
7. **Windows client build**, when there is a machine for it.

---

## Things worth knowing before changing anything

Read [../docs/WorkingOnSoundChex.md](../docs/WorkingOnSoundChex.md). It is the
accumulated lessons of this session — the database rule, why tests here have a
habit of passing without testing anything, and the platform behaviours that
caused most of the hard bugs.
