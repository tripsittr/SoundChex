# Issues

Every piece of tracked work, and where it got to. One line each.

An "issue" here is **anything we decide to build or change** — a feature, an
improvement, a bug, a piece of cleanup. A feature request gets an entry exactly
as a defect does, because the question being answered is the same: what are we
doing, what is waiting, and what did we decide against.

**How this works.** Say what you want in any form — a sentence is enough — and
it gets an entry here before any work starts. Entries move down the file as
they resolve: **In progress** → **Open** → **Deferred** → **Done**.

Done sits at the bottom because it is the section least often read. Deferred
sits above it because a decision not to do something is still live: it is worth
as much as a fix, and is otherwise rediscovered every few months.

**IDs** are `S-nn` and never reused. Reference one and I will know exactly what
you mean.

**Verified** means it was checked against the real library or a real device,
not that a test passed. Both are noted where they differ.

---

## In progress

| ID | What | Notes |
| --- | --- | --- |

## Open

| ID | What | Notes |
| --- | --- | --- |
| S-46 | Snapshot a file's own metadata at the moment it arrives | The embedded tags, the original filename and path, size and hash, recorded once as an `intake` version before anything renames or enriches. `metadata_versions` already holds 5,829 rows but every one has `reason = enrichment`, so the state a file arrived in exists nowhere. S-44 is exactly what that costs: the fix has to infer the original filename from the shape of a title, because the real one was overwritten and never kept |
| S-45 | Three orphaned rows point at files that were re-filed | 622, 623, 1107. The files exist under corrected names and were catalogued again as 3484, 3499, 2984, so each song is in the library twice with one copy unplayable |
| S-53 | iOS offline still failing: a third IndexedDB database was never fixed | The connection-recovery fix went into `downloads.js` and `mirror.js`; `write-queue.js` opens a third and was missed, so the same "database connection is closing" rejection kept arriving from a database nobody had looked at. Reported again on the current build, which is how it was found |
| S-73 | Transferring profiles separately from the catalogue | The receiver imports the whole database, which carries profiles with it — so choosing "profiles" without "the catalogue" currently does nothing. Either the option goes, or it means importing profile rows into an existing catalogue, which is a merge and a different problem |
| S-74 | `npm run build:server` fails at the DMG step | `bundle_dmg.sh` errors, and each failure leaves a mounted disk image and `rw.*` artifacts behind that break the next attempt — so it fails worse each time until they are detached by hand. Worked around with `--bundles app`, which is enough for a local install and not for distributing one. Tauri also exits **0** on this failure, so it reads as success unless the output is read |
| S-75 | Windows PHP has no CA bundle, so every HTTPS request fails | GitHub #4. The first real transfer hit `cURL error 60: unable to get local issuer certificate` — the source's certificate is a valid Let's Encrypt one with a complete chain, and the receiving machine simply had nothing to verify against. Not specific to transfers: TMDB, MusicBrainz, artwork and subtitles all fail the same way. Setup guide updated and the error now explains itself |
| S-52 | No back arrow — getting out of a page means navigating all the way round | An artist, album or item page is reached from a list and has no way back to it except the tab bar, which lands on the top of a different screen. Needs to know where it came from rather than guessing: `history.back()` is wrong on a page opened directly or arrived at from the offline shell |
| S-66 | App distribution: sideloading, stores, and what each costs | Reference rather than a plan, written 13 Aug with prices from that date. Overlaps `docs/BuildingOnEachPlatform.md` for the build half; what it still carries is the distribution question — sideloading against a store, and what each target charges. Kept for that |
| S-02 | Reader is slow to open and to turn pages | Not diagnosed. Page content, OCR text and images all stream per page; the mirror does not cover the reader at all |
| S-03 | Artist, album and item pages are not in the device mirror | Six list screens are; these three are pure server round-trips, ~900ms each over the relay |
| S-04 | Five mobile specs fail only in a full run | `phone-dl`, `library-refresh`, `downloads-batch`, `download-logging`, `player-session` — each passes alone and as a whole spec, and fails in the 282-test run. Shared IndexedDB and one seeded database across a single worker is the likely cause, but it is unproven. Until it is understood, a full-suite failure cannot be told from a real one, which is the actual cost |
| S-06 | Native downloads: IndexedDB caps the library at ~1 GB | Plan written: `NativeDownloads.md`. Re-measured 22 Aug: **34.95 GB across 7,031 tracks**, against the plan's 7.71 GB — the library has grown 4.5× and the ~1 GB cap now holds under 3% of it. "Download all" cannot succeed on the phone until this is done |
| S-07 | Background downloads and audio stop when the app is backgrounded | Plan written: `NativeOfflineBridge.md`, ~6 days across five steps, none started. Unblocked. Depends on S-06 for where the bytes land, so that goes first. Step 1 is a day proving the plugin path on the device before any of the rest is worth writing |
| S-08 | HLS adaptive streaming | Largest remaining item. No plan written |
| S-09 | Direct route in from outside the house | Plan written: `DirectRemoteAccess.md`. Needs router and DNS work that is not code |
| S-11 | `soundchex.json` and `soundchex-addresses.json` are unauthenticated | Deliberate on a tailnet, an information leak on the open internet. They name every address the server answers on. Blocks S-09 |
| S-12 | AcoustID, Spotify and OpenSubtitles have no API key | Sources skip themselves silently. Now logged, not yet fixed |
| S-13 | Three songs match no metadata provider | Items 1445, 2002, 2473. Complete with `match_confidence = none`; will never gain metadata without a manual match |
| S-55 | Move a whole server to another machine — database, files and profiles | Plan written: `ServerTransfer.md`, ~7.5 days. 8,315 files, 46.3 GB, so resumability is the feature rather than polish. The receiver asks and the request sits **pending until a person on the source approves it** — no credential is copied between machines, and approval is revocable while the transfer runs. Every file hashed before and after; a mismatch is deleted rather than kept. Measured before designing: media gzips 0.4% and the database 85% |
| S-54 | Buildable on Mac, Windows, Linux, iOS and Android | In progress. The web app is platform-neutral; the host-specific parts are not. Address detection was macOS-only and is now per-platform. Service management is launchd and has no Windows or Linux equivalent. Android has no Tauri project generated at all. Each platform needs its toolchain documented and one real build to prove it |
| S-14 | Windows and Linux client builds are untested | The config approach carries over unchanged, but neither has been built |
| S-15 | Tailnet key expires 2027-02-09 | The Mac drops off the tailnet that day. Disable key expiry in the Tailscale admin console |
| S-16 | Test suite is slow | `workers: 1`, one SQLite database and one seeded library. The fixable part is the remaining hardcoded `waitForTimeout` calls |
| S-17 | Device and session tracking | "What devices are online, using the server, and what they are doing." `device_reports` is a starting point |
| S-18 | APNs push notifications | Paid account makes it possible; `notifications.js` already sends what push would carry |
| S-37 | `MusicCredits::fromMusicBrainz()` is never called in production | Written and tested, but only tests call it. Enrichment always parses the credit string, so credits never get MusicBrainz's stable artist ids even when a recording matched. The better source is built and unused |
| S-38 | `useLocalSource()` in `download-button.js` is dead code | Nothing references it. The player and video use `localUrl()` directly, so it is superseded rather than missing — but it should go before someone wires it up in parallel |
| S-39 | 11 metadata sources registered but commented out | Discogs, Last.fm, Genius, Deezer, OMDb, Trakt, TVMaze, TVDB, Google Books, LibraryThing, Fanart.tv. Nothing breaks by their absence |

## Deferred

| ID | What | Why |
| --- | --- | --- |
| S-33 | Bundle Tailscale into the app | On iOS a real tunnel needs a Network Extension entitlement and its own process; the userspace version would not carry WebView traffic, which is all the traffic. Three to four weeks to match what installing Tailscale does in ten minutes. Worth revisiting if the app is ever distributed to other people |
| S-34 | Build a Tailscale equivalent | NAT traversal and a relay network are the expensive parts, and a routable public IP means they can be skipped entirely. 6–12 months to own something that already works |
| S-35 | A second updater endpoint for redundancy | The tailnet IP serves 301 to HTTPS and the certificate covers the MagicDNS name, so the hop fails TLS. A fallback needs a certificate, not an array entry |
| S-36 | Splitting `music_metadata.artist` itself | It is what the file says, and `LibraryOrganizer` builds `Artist/Album/` from it. Changing it moves files on disk |

---

## Done

| ID | What | Fixed | Verified |
| --- | --- | --- | --- |
| S-19 | Long press highlights text instead of opening the menu; links show "Open in Safari" | `1ad8cf7` | Built CSS asserted; needs a device check |
| S-20 | Cannot change the server address — it connects before you can | `030ca5c` | 24 shell tests |
| S-21 | Batch download loses a track to one dropped packet | `e5dcc70` | 5 tests, retry verified to fail without the fix |
| S-22 | Remove button on downloads does nothing | `818c62c` | 10 tests, desktop and mobile |
| S-23 | No way to remove all downloads | `818c62c` | Confirmation names the count and the space |
| S-24 | iOS closes IndexedDB and every later transaction fails | `566ae5b` | Reported by the phone: 1 UnknownError, then 27 failures |
| S-25 | App picks the 843ms relay over a 92ms direct route | `dcd267f` | Vitest ranking suite, fails without the fix |
| S-26 | Artists page lists "$uicideboy$, Bones" as its own artist | `84a1500` | 1,034 → 883 artists; `$uicideboy$` 342 → 446 tracks |
| S-27 | Songs not grouped under a primary artist | `9e574a0` | 4,080 tracks credited, 419 collaborations |
| S-28 | An artist's collaborations are missing from their page | `84a1500` | "Appears on" reads the credits, not the string |
| S-29 | Enrichment silently skips a source with no API key | `31d2fa5` | Names AcoustId and Spotify against the real library |
| S-30 | Scan knocks over its own enrichment jobs | `4e02ca8` | 12 dropped jobs retried, all 12 ran |
| S-31 | now-playing sheet flake, ~1 run in 20 | `75c2d20` | 70 consecutive passes |
| S-32 | Converted film unplayable after a catalogue rebuild | `603427a` | Backrooms plays; original archived, not deleted |
| S-49 | "these tracks is already on this device", uncapitalised | `ee35c3e` | 5 tests; every label case checked by hand |
| S-67 | AI tagger pivot | earlier | Marked DONE_ by the plan workflow. Plan deleted |
| S-68 | Admin panel themed to match the media centre | earlier | Marked DONE_. `resources/css/tokens.css` shares one palette. Plan deleted |
| S-69 | Profile permissions, gating every admin screen | earlier | Marked DONE_. `RestrictsToAdmins`, `Profile::can()`. Plan deleted |
| S-70 | Test coverage, and the five bugs writing it found | earlier | Marked DONE_. Plan deleted; what it taught is in `docs/WorkingOnSoundChex.md` |
| S-71 | Filing TV episodes into Series/Season trees | earlier | Marked DONE_. `LibraryOrganizer`. Plan deleted |
| S-72 | Uploads, and an uploader role that reaches only that page | earlier | Marked DONE_. Plan deleted |
| S-56 | Unified search across titles, metadata, dialogue and book text | earlier | `SearchService`, `BookSearch`, routes live. Plan deleted |
| S-57 | Music keeps playing while you browse | earlier | `now-playing.js`, `player.js`, `player-session.spec.js`. Plan deleted |
| S-58 | Tauri shell for macOS, Windows, Linux, iOS | earlier | `src-tauri/`, built for macOS and iOS. Plan deleted; per-platform detail is in `docs/BuildingOnEachPlatform.md` |
| S-59 | Offline downloads of music, films and books | earlier | `downloads.js`, `download-queue.js`, 14 tests. Plan deleted |
| S-60 | A server app with its own service controls | earlier | `tauri.server.conf.json`, the Services page. Plan deleted |
| S-61 | Music browsing that works like a music app | earlier | `music-nav`, album and artist pages, primary-artist grouping. Plan deleted |
| S-62 | The offline shell shipped inside the app | earlier | `scripts/embed-offline-shell.mjs`, `embedded-shell.spec.js`. Plan deleted |
| S-63 | Offline-first browsing, search and playback | earlier | `library/offline-shell.js`, mirror, `takeOver()`. Plan deleted |
| S-64 | A converted film is a real filed item | `603427a` | `ConversionFiler`, originals archived. Plan deleted |
| S-65 | Mobile access and offline playback | earlier | Superseded by S-06 and S-07, which scope what the web platform cannot do. Plan deleted |
| S-01 | Artist profiles: images, bios, years active | `743b674` | Plan deleted. MusicBrainz and Wikipedia, no API key. 9 tests. `library:artist-profiles` paces itself at the published rate limit |
| S-50 | A device report should identify the device | `720cbf6` | Name, type, IP, app and shell version, full user agent, timestamp. Verified end to end |
| S-51 | Reports filterable by device, type and log kind | `720cbf6` | 5 tests. Built onto the existing admin page rather than the duplicate resource I started |
| S-43 | Logging everywhere it counts | `2e82c6f` | Every failure path that showed a message and recorded nothing now says what happened: playback, sync, the reader, downloads, playlists, shuffle, subtitles, a downloaded film that could not be read, address learning, and both offline render paths. 13 tests pin the coverage. The catches left silent are deliberate — private-browsing storage, an expected offline — and listed as such in AGENTS.md |
| S-41 | The Tauri shell's own assets cannot update themselves | `720cbf6` | Not a broken updater: the served layer updates within 60s and the phone reported four builds progressing in one evening. The shell is compiled into the binary, so it now stamps itself and reports it — a stale shell is visible rather than suspected. Making it *self*-update on iOS is S-07's territory |
| S-48 | Carousel errors on the Mac: `Can't find variable: rail` | not a defect | Transient. The reports carry `reloading-for-build` immediately before, and the build they name is two rebuilds old: cached HTML calling `rail()` against a bundle mid-replacement. Verified clean across four browse screens on the current build |
| S-47 | Lists do not update while a page is open | `8a31e12` | 7 tests, desktop and mobile; player and scroll position both survive |
| S-44 | Titles carry the artist: "Gold - Imagine Dragons" | `6646958` | 4,377 restored from file tags; 0 remain. Every change snapshotted to `metadata_versions` |
| S-42 | No toast when going offline or coming back | `8af5001` | 6 tests, desktop and mobile |
| S-40 | Playlist rows have long press but no menu to open | `1ad8cf7` | `track-menu` added; every song surface now has one |
| S-05 | iPhone not on the tailnet | — | Phone is on it as `100.77.35.44`; server answers in 77ms. Confirm the app settles there rather than the Funnel |
| S-10 | `/login` rate limiting | already built | 5/min by IP **and** by email, so spraying addresses does not defeat it. Listed in error |

---

## Audit notes

**Statuses verified 22 August 2026** against the code and the live database
rather than against what the entries claimed. Three moved:

- **S-05** — the iPhone is on the tailnet now (`100.77.35.44`), and the server
  answers it in 77ms against the Funnel's 843ms.
- **S-10** — listed in error. `/login` has had rate limiting all along: 5 a
  minute by IP *and* by email, so spraying addresses does not defeat it.
- **S-40** — fixed while auditing, since it was a one-line omission.

Everything else was confirmed still true: S-11 (both identity endpoints answer
unauthenticated), S-12 (AcoustID, Spotify and OpenSubtitles still empty), S-13
(all three songs still unmatched), S-16 (45 `waitForTimeout` calls across 14
files), S-37 (`fromMusicBrainz` still called only from tests).

Searched 22 August 2026 for unfinished work: TODO/FIXME/HACK markers (**none**
in `app/`, `resources/` or `src-tauri/src/`), skipped tests (three, all
environment-conditional and legitimate), unreferenced views (none), pending
migrations (none), registered-but-missing classes (none).

What it did turn up is S-37 through S-40 above. S-37 is the one that matters:
the better credit source is built, tested, and never reached.

## Under discussion

Not yet decided, and worth deciding before more work goes into either side.

**A native iOS/Android app, keeping the web app for desktop.** Raised after
long press, link previews and background downloads each turned out to be the
platform fighting the web app. The case each way is in `NativeClients.md`.
