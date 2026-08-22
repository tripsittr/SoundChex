# 002 — Artist credits and profiles, offline recovery, and a way to track what we are doing

**Merged** 2026-08-22 · **Issues** S-01, S-19 to S-32, S-37, S-40 to S-51

100 commits. Most of the application, in practice — this is the first merge
since June, so it carries the media centre, the reader, profiles, offline
downloads and the Tauri shells alongside the work below.

## What changed

### An artist's collaborations appear on their page

An artist page matched the artist string exactly, so a collaboration was a
different artist from the person who made it. `$uicideboy$` had 342 tracks of
their own and appeared on 456; the other 114 were invisible. The artist index
had the same problem in reverse — one artist listed once per collaborator they
had ever recorded with.

Credits now live in `media_item_person`, the table that has carried author and
director since books and film were built, with MusicBrainz's structured credits
preferred over parsing a string. 1,034 artist strings became 883 real artists.

### Artist pages have a photograph, a biography and years active

From MusicBrainz and Wikipedia, neither of which needs an API key. The match is
deliberately strict — an exact name and a confidence score of 90 or better,
refused otherwise — because a wrong artist puts someone else's photograph and
life on your record.

Most of a self-hosted library will match nothing, and the page reads the same
without it.

### Titles no longer contain the artist twice

4,377 tracks — 72% of the music — displayed as "Gold - Imagine Dragons" with
"Imagine Dragons" printed underneath. The filer wrote the artist into the
filename, the scanner read filenames back as titles, and the guard meant to
promote the real tag compared against a filename the filer had already changed.

### Offline mode survives the app being backgrounded

iOS closes IndexedDB out from under a page, after which every transaction threw
for the rest of the session — which is what "nothing downloaded" was. Three
databases needed the fix; two got it first and the bug kept being reported.

### The app stops preferring a slow route

It ranked the 843ms relay above a 92ms direct address at launch, because the
relay is a `.ts.net` name and stability outranked speed.

### Downloads

Removing one worked but said nothing when it failed, so a dead button and a
broken one looked identical. "Remove all" is new, behind a confirmation naming
the count and the space. A batch download lost a track to a single dropped
packet; it now retries three times.

### Failures say what happened

The app runs on a phone that is not in the room, so most of these were silent:
playback, sync, the reader, downloads, playlists, address learning and both
offline render paths now record what went wrong. Device reports carry the
device name, type, IP, app and shell version.

### Issues.md

Every feature, fix and bug in one file, with the practice written into
AGENTS.md so it outlives the session it was asked for in.

## Worth knowing

- **36 migrations**, most of them the original schema. A fresh clone runs them
  cleanly.
- **4,377 titles were rewritten** from their own file tags. Every change was
  snapshotted to `metadata_versions` first, so it is reversible — but it ran
  against the real library rather than as a migration, so another clone needs
  `library:repair-titles --apply`.
- **4,080 tracks gained credits** and 419 collaborations were split.
- The TMDB API key was empty, which meant no film could get a year, runtime or
  rating — silently, because a source with no key skips itself without a word.

## Still wrong

- **Five mobile specs fail only in the full run** and pass alone. Test
  interference rather than product failures, but unproven — and while it stands,
  a full-suite failure cannot be told from a real one. Tracked as S-04.
- **The Tauri shell cannot update itself.** The served layer updates within a
  minute; the connect screen and offline shell are compiled into the binary and
  need a reinstall.
- **iOS storage is capped at about 1 GB** against a 35 GB music library, so
  "Download all" cannot succeed on a phone.

## Tests

289 PHP · 87 Vitest · 277 of 282 Playwright.
