# Library pipeline — import, identify, dedupe, check, file, review

Everything that happens to a file between "it appeared in a watch folder" and
"it is a correctly named, correctly tagged item in the right folder, or it is in
Needs Review with a reason". Supersedes the metadata half of
`LibraryCleanup.md` (S-94/S-95), which stopped at "diagnose before proposing";
this is the diagnosis and the proposal.

Written 5 October 2026 against `main` at `6768706`. File and line references are
to that commit.

---

## Contents

1. [The short version](#1-the-short-version)
2. [What the pipeline must guarantee](#2-what-the-pipeline-must-guarantee)
3. [How it works today](#3-how-it-works-today)
4. [Findings](#4-findings)
5. [Why the current design produces these bugs](#5-why-the-current-design-produces-these-bugs)
6. [The design](#6-the-design)
7. [Rollout — phases, issues and acceptance](#7-rollout--phases-issues-and-acceptance)
8. [Re-processing the existing library](#8-re-processing-the-existing-library)
9. [How we will know it works](#9-how-we-will-know-it-works)
10. [Rule changes for AGENTS.md](#10-rule-changes-for-agentsmd)
11. [Decisions needed](#11-decisions-needed)
12. [Appendix — every finding, by file](#12-appendix--every-finding-by-file)

---

## 1. The short version

Most of the parts exist: a scheduled scanner, nine metadata sources, a
nine-way duplicate detector with a keep-best rule, an organizer, a confidence
gate, metadata history, a Needs Review page and a client endpoint for reporting
an item. **What does not exist is a pipeline.** The parts run in the wrong
order, inside one job, with no record of how far a file got, and several kinds
of failure land nowhere.

The consequences, measured or read from the code:

- **Files can be deleted or overwritten** by the organizer and the conversion
  filer on paths that are easy to hit on macOS (§4.1). This comes first.
- **Every film or show matched by title search is marked `Exact`**, which is
  the one value allowed to rename and move a video file (§4.1.3).
- **All 8,440 music rows read `match_confidence = none`** (LibraryCleanup.md),
  for five separable reasons, none of them "the library is unmatchable" (§4.4).
- **Duplicates are checked before anything is identified**, so in practice
  only byte-identical copies are found at import (§4.3).
- **There is no notion of a version or edition.** A 4K and a 1080p copy of one
  film, or a remaster and the original, can only be "duplicate" or "not".
- **Nothing checks quality.** A truncated file, a CAM rip, a transcoded "FLAC"
  and a file with no audio stream all import as healthy.
- **Review is split across five columns and two tables**, system problems have
  no reason codes, and there is no way to pick a different match from the
  queue.

The fix is in eight phases (§7). Phase 1 is safety and should ship before
anything else touches the live library. Phases 2–3 make the existing library
match. Phases 4–7 add what is missing. Phase 8 re-processes the 8,335 items
already catalogued, dry-run first.

---

## 2. What the pipeline must guarantee

The original ask was "every file scanned, tagged, renamed and moved on initial
import without issues". Read literally that cannot be met — some files are
damaged, some are ambiguous, some are genuinely two things. The guarantee this
design makes instead:

> **Nothing is ever silently wrong.** Every file ends in exactly one of two
> states: *filed* (identified with confidence, tagged, named and moved), or
> *in Needs Review with a machine-readable reason and the evidence to resolve
> it*. No third state, no file stuck invisibly, no file moved on a guess, no
> file lost.

The individual guarantees, each of which §9 tests:

| # | Guarantee |
|---|---|
| G1 | No file is ever deleted or overwritten except a verified byte-identical copy, after a re-hash, by a different inode. |
| G2 | Every file move is journalled before it happens and can be undone. |
| G3 | A file is moved only when its identification clears the confidence gate — for every media type, music included. |
| G4 | A crash at any point leaves every file either at its old path or its new path, with the row pointing at the one that exists, and the item resumes where it stopped. |
| G5 | Every item that is not filed has an open review item with a reason code. Nothing is hidden from the library *and* absent from review. |
| G6 | A provider being down or rate-limiting is recorded as an error and retried, never recorded as "no match". |
| G7 | A field a person edited is never changed by enrichment. |
| G8 | Two copies of the same work in different editions or qualities are versions, not duplicates. Only same work + same edition + same quality tier is a duplicate. |
| G9 | Every import path (scan, upload, CLI, CSV, transfer) goes through the same pipeline. |
| G10 | Every user report and every system flag can be resolved from the Needs Review page without editing a form by hand. |

---

## 3. How it works today

### 3.1 The flow

```
library:scan (cron, every N min, withoutOverlapping)        routes/console.php:37-43
  └─ LibraryScanner::scan()                                  app/Services/LibraryScanner.php
       for each file under watch folders + the whole private disk:
         skip if path already known                          :80-99
         skip if mtime < settle_seconds                      :105
         classify() → music | movie | show | book            :510-587
         catalog()  → media_items row, status=pending        :592-624
         attachToSeries() (episodes)                         :332
         DuplicateDetector::check()  ← hashes inline, BEFORE any metadata   :144
         LocalArtwork                                        :151
         dispatch EnrichMediaItemJob                         :154
         dispatch ImportSubtitlesJob | ExtractBookAssetsJob  :165-171
       sweepMissingFiles()                                   :214

EnrichMediaItemJob (tries=3, backoff 60s)                    app/Jobs/EnrichMediaItemJob.php:53-103
  status=processing
  MetadataPipeline::run()  ← sources in priority order, fill-blank only
  status=complete | needs_review
  credits → title filters → album normalise → missing-album flag
  → cover embed (rewrites the file) → availability
  → LibraryOrganizer::fileIntoLibrary()  ← moves the file
  exception → status=failed
```

There is no Bus chain or batch. The only per-file state is
`processing_status`, and `ResolvedScope` hides every row that is not
`complete` (`app/Models/Scopes/ResolvedScope.php:61-69`).

### 3.2 What each stage has

| Stage | Exists | Missing |
|---|---|---|
| Discover | Scheduled scan, mtime settle, known-path skip, unreadable reporting, cautious missing-file sweep | Watcher, size-stable settle, junk/sample exclusion, crash recovery |
| Classify | Extension map + ffprobe for ambiguous containers (`ContainerProbe`) | `.ts .m2ts .flv .vob .m4b`, date-based and absolute-numbered episodes, audiobooks |
| Parse | Homegrown regex: `EpisodeParser`, `stripReleaseTags`, `cleanTitle` | Edition capture, path ids (`{tmdb-…}`), several real-title mangling bugs (§4.2) |
| Probe | Music: format, duration, sample rate, bit depth (getID3, `FileTagger.php:79-93`) | **All video technical data.** Resolution, HDR, codecs, channels, languages, bitrate — not stored anywhere |
| Hash | Full-file xxh128, `content_hash`, skipped over 8 GB | Runs inside the scan loop; never run by four of the five import paths |
| Identify | MusicBrainz, AcoustID, TMDB (film/TV), TVDB, OpenLibrary, iTunes, Last.fm, Deezer, Spotify; 7-day `LookupCache`; plugin sources | Candidate scoring, stored alternatives, rate limiting, error ≠ no-match |
| Merge fields | Fill-blank-only; `MetadataVersion` snapshot + restore | Field locks, provenance per field, a re-identify (overwrite) mode |
| Duplicates | Bytes, ISRC, MBID, AcoustID, fuzzy, likely, TMDB, episode, same-title; `decideKeeper()`; re-hash before delete; `DuplicateDecision` per pair | Runs before identification; no versions/editions; not scheduled; quality ranking is file size |
| Quality | — | Everything |
| Organize | Fixed templates per type, never-overwrite suffixing, size check on cross-volume copy, confidence gate for non-music | Templates, disc folders, album-artist folders, sidecars, journal, undo, inode-safe comparisons |
| Review | One page (`DuplicateResource`, labelled Needs Review), tabs, user reports via `POST /api/v1/items/{item}/review`, Looks fine, Re-enrich | Unified reasons, candidate picker, lock, dismiss, several failure kinds |

### 3.3 Test coverage

Strong per stage: `LibraryScannerTest` (25), `LibraryOrganizerTest` (18),
five duplicate suites, five review suites, `EnrichmentReportTest`,
`MusicMatchConfidenceTest`, `StrandedJobsTest`. Missing: any end-to-end
scan→identify→dedupe→file test, the enrichment failure path, organizer
failure and partial-move recovery, crash between catalogue and dispatch,
rate limiting, case-insensitive paths, the review endpoint's authorisation.

---

## 4. Findings

Marked **[verified]** where the code path was re-read line by line for this
document and the failure traced by hand. Everything else comes from a full
read of the stage and should be reproduced by a failing test before it is
fixed — AGENTS.md rule 3. Nothing here was run against the live library;
line numbers outside the verified items may drift by a few lines.

### 4.1 Can lose or misfile the user's files

**4.1.1 Case-only rename deletes the only copy. [verified]**
`LibraryOrganizer::fileIntoLibrary()` compares source and target as strings
(`LibraryOrganizer.php:145`). On a case-insensitive volume — the default on
macOS and Windows — a target that differs only in case (`tidyTitle` or
`normalizeAlbum` re-casing a title just before filing does exactly this) is a
different string but the same file. `isSameFile()` (`:477`) hashes both paths,
gets the same bytes because they *are* the same file, and calls
`adoptExisting()` (`:170`), which `unlink`s the source (`:517`) — the only copy.
Symlinked library roots trigger the same path. `DuplicateDetector::merge()`
has the same weakness when two rows hold different spellings of one path
(`DuplicateDetector.php:627`).

**4.1.2 Conversion filing overwrites the original. [verified]**
`ConversionFiler::filedPathFor()` is the organizer's target with the
conversion's extension (`ConversionFiler.php:218-234`). For an original that is
already an `.mp4` in its filed location — HEVC in MP4, say — that is the
original's own path. `move()` renames the conversion over it with no
existence check (`:262-291`, called at `:81`). The next step then archives
what is now the conversion, and `file_path` points at nothing. The archive
path has no collision check either. It also writes `file_path` without
clearing `content_hash` (`:114-127`), breaking AGENTS.md rule 2.

**4.1.3 Every TMDB title-search match is `Exact`. [verified]**
`Movie/Tmdb.php` calls `promoteTitle()` (`:71`) — which overwrites the item's
title with TMDB's — and only then `recordConfidence()` (`:76`), which marks
the match `Exact` when TMDB's title equals the item's title
(`TalksToTmdb.php:234-235`). After promotion it always does. Same order in
`Show/Tmdb.php:72/77`. `Exact` is the value that permits moving a video file,
so wrong film matches are being renamed and refiled.

**4.1.4 MusicBrainz labels a text-search match `Exact`. [verified]**
`$matchedByIdentifier` is true whenever an ISRC is *present* (`MusicBrainz.php:66-67`),
even if the ISRC lookup failed and the recording came from the text search.

**4.1.5 The admin "auto-organize" switch does nothing. [verified]**
`EnrichMediaItemJob.php:385` reads `config('library.auto_organize')`.
`LibrarySettings::autoOrganize()` (`LibrarySettings.php:87`) is what the
settings page writes to, and nothing calls it. Turning auto-organize off in the
UI does not stop files moving.

**4.1.6 Music bypasses the confidence gate.**
Music is exempt "because its tags are authoritative" (`LibraryOrganizer.php:70-77`,
AGENTS.md rule 2). But MusicBrainz enrichment writes `artist` and `album`
(`MusicBrainz.php:404, 448`), so music is filed on API guesses, including items
sitting in `needs_review`.

**4.1.7 "Keep the duplicate" orphans the original.**
`DuplicateDetector::resolveKeeping(keepDuplicate: true)` deletes the original's
file and never updates its row (`:716-731`): dead path, no status, plays and
playlist entries stranded, other duplicates still pointing at it.

**4.1.8 The filed copy can be flagged as the duplicate of a loose one.**
`pickOriginal()` ranks the candidates but never the item being checked
(`:579-583`). When the older, filed row is checked first against a newer loose
copy, the filed row becomes `duplicate_of` the loose one — and under
`duplicate_action=auto` it is the one deleted.

**4.1.9 Bulk "Merge selected" deletes title-only matches.**
`DuplicatesTable.php:671` merges `Likely` and `SameTitle` pairs, tie-broken by
newest — the exact thing LibraryCleanup.md ruled out. Content matches never
check `match_confidence`, so a loose ISRC or TMDB match can delete a different
song's or film's file.

**4.1.10 `duplicate_action=report` is not enforced.** The settings page
promises "never act, even from the review screen"; neither the table actions
nor `library:duplicates --merge` check it.

**4.1.11 Crash windows in the organizer.**
- After `rename()` but before `saveQuietly()` (`:178` → `:224`): the row points
  at the old path, the sweep marks it `file_missing`, the next scan catalogues
  the moved file as a new item.
- After the cross-volume copy but before the source `unlink`: two copies.
- Cross-volume copies are verified by size only, and the source `unlink`
  result is not checked.
- `uniquePath()` and `rename()` are not atomic; two workers filing to the same
  name overwrite each other. After 999 suffixes it returns the occupied path
  (`:545`).

**4.1.12 Cover embedding rewrites files and leaves a stale hash.**
`CoverEmbedder` runs on every re-enrichment of an `Exact` music match with no
"already embedded" check, and `content_hash` is not cleared afterwards.

### 4.2 Wrong results

**Ingest and parsing**
- **Series rows are never enriched.** `attachToSeries()` creates the series
  `pending` (`LibraryScanner.php:339-353`) and nothing dispatches its job. The
  series stays hidden, and `Show/Tmdb.php:95-101` makes every episode wait for
  a series TMDB id that never arrives.
- `cleanTitle` strips a leading number (`:718`): "7 Rings" → "Rings",
  "99 Luftballons" → "Luftballons". Dots become spaces: "Mr. Brightside" →
  "Mr Brightside".
- `stripReleaseTags` cuts at the first marker word (`:750-784`): "A Complete
  Unknown 2024" → "A 2024". Takes the first year: "Blade Runner 2049 2017" →
  2049. Editions are stripped and discarded.
- `EpisodeParser::isMultiEpisode` uses `PATHINFO_FILENAME` (`:171`), the bug
  `withoutExtension` exists to avoid: `The.Bear.S01E01E02` → a film.
  Underscore separators defeat `\b`: `The_Bear_S01E02` → a film. Season 0
  types as Show but `parse()` refuses it (`:157`).
- `FileTagger` reads `musicbrainz_recordingid`, but Picard writes
  `MUSICBRAINZ_TRACKID` (Vorbis) and `TXXX:MusicBrainz Track Id` / UFID (ID3) —
  the recording id is probably never read. Album artist, release-group,
  compilation flag and AcoustID id are not read. Camelot keys rejected
  (`:471`).
- `looksLikeAFilename` (`FileTagger.php:289-327`) only promotes the embedded
  title when the stored title is the raw filename, but the scanner has
  already cleaned it — so a correct tag title is often ignored.
- The whole private storage disk is swept: PDFs under `reports/` or `backups/`
  become books. `sample`, trailer and featurette files become films.
- Sidecar `.srt` import is queued *after* enrichment, which has already moved
  the video; the organizer does not move sidecars, so subtitles are orphaned.
  The glob `base*.srt` (`SubtitleImporter.php:270`) lets `Alien.mkv` take
  `Aliens.en.srt`.
- Settle is mtime-only: a paused download is catalogued half-written.
  `BulkUpload::save` scans immediately, so uploads are always "unsettled".
- Five ingest paths (scanner, `library:import-music`, `CreateMusic`, the
  `ListMusic` upload, CSV) diverge; the last four skip size, hash, duplicate
  check, intake history, the catalogued event, artwork and subtitles.
- `ReclassifyTelevision` flips `type` without creating `showMetadata`, and
  tells the user to rescan, which skips known paths.

**Identification**
- No title or duration similarity check anywhere. iTunes sets `Fuzzy` if any of
  five albums has a matching artist (`ItunesSearch.php:99-105`), and its
  normaliser turns every non-Latin artist into `''`, which `str_contains`
  matches against anything (`:149-156`). Deezer and `CoverArtFetcher` share it.
- MusicBrainz keeps the *earliest-dated* of up to eight candidates
  (`MusicBrainz.php:261-290`) — usually right for studio albums, wrong for
  anything first released on a compilation.
- AcoustID takes the top result at score ≥ 0.5 and marks it `Exact` with no
  further check (`AcoustId.php:107, 148-153`).
- Fill-blank-only means the first source to write a field wins forever; a
  corrected MBID re-enriched keeps the old album, year and label.
- `promoteTitle` overwrites user-typed titles unconditionally
  (`Movie/Tmdb.php:275`, `Show/Tmdb.php:193`, `OpenLibrary.php:557`). The
  contract's "never overwrite `source = manual`" is enforced nowhere.
- `MetadataHistory::restore` does not restore people/credits, though they are
  snapshotted (`:174-184`).
- Spotify cannot run: Integrations exposes only `spotify_client_secret`
  (`Integrations.php:515`), so `supports()` needs a client id nobody can enter;
  the audio-features endpoint is deprecated for new apps; search takes
  `limit=1` with no artist check; an error body throws on `['tempo']`.
- Plugin-registered sources: the `$priority` argument is stored and ignored,
  and a class registered twice runs twice. `requiredSettings()` is never read
  by the Integrations page, and Last.fm/TVDB return lists, not key⇒label maps.
- Cover Art Archive is never used, though release MBIDs are stored. iTunes,
  Deezer and MusicBrainz each have two separate clients.

**Duplicates**
- Run at scan time before identification, so ISRC/MBID/TMDB/episode passes
  have nothing to compare. Not scheduled. `DetectDuplicatesJob` docblock
  claims a scheduled sweep that does not exist.
- Keep-best for video is "bigger file by 10%"; for music, size×8/duration
  (cover art counts as audio), then sample rate (an upsampled FLAC wins).
  Codec, lossless-vs-lossy, bit depth, resolution and source are ignored.
- Pending rows are re-flagged and `DuplicateDetected` re-fired to plugins on
  every sweep.
- Merges leave plays, ratings and playlist entries on the hidden losing row.
  `MergeDuplicatePlaylists` drops hidden tracks and loses order.

**Review and orchestration**
- Re-enriching a reported item returns it to the library but leaves the report
  open; it then appears only on the All tab, where Looks fine is not offered.
- `flagMissingAlbum` runs after `enrichment_report` is saved, so the "Why?"
  shows the wrong reason.
- Failed items cannot be marked Looks fine. Kept duplicates sit forever in the
  per-type Needs review tab but not the central one; the badge double-counts.
- `POST /items/{item}/review` lets any user or kid profile hide any item from
  the whole library, has no throttle, and returns 201 vs 404 in a way that
  reveals hidden ids. It does not fire `MediaItemReviewFlagged`.
- Nothing re-queues `pending`, `processing` or `failed` rows. A crash between
  `catalog()` and dispatch leaves a row hidden forever.
- No rate limiting on any provider except the artist-profile fetcher.
  `music:reenrich --stagger=1200` assumes one call per item; a MusicBrainz text
  match costs up to eleven.
- `music:reenrich` compares an enum to a string (`:81`), throws on `--sample`
  (`:90`), and its inline path skips the history snapshot, status, credits and
  organizer.
- Worker start releases all reserved jobs (`AppServiceProvider.php:119-157`),
  which is only safe with exactly one worker.

### 4.3 Lands nowhere

These problems leave an item hidden from the library *and* absent from review,
or visible and silently wrong:

| Problem | Where it ends up today |
|---|---|
| Fuzzy music match | `complete`, filed, one line in `enrichment_report` |
| Organizer move failure | log only |
| `file_missing` | hidden, excluded from the review query |
| Unreadable file at scan | counted, logged, no row |
| Stuck `pending` / `processing` | hidden, no tab |
| Provider down / rate-limited | reads as "no match" |
| User reports Duplicate / Cover / File | all land on the Metadata tab; a Duplicate report does not run detection |
| Any quality problem | not detected |

### 4.4 Why all 8,440 music rows read "no match"

Not one cause, five, each fixable on its own:

1. **No backfill.** `match_confidence` was added with `default('none')`
   (migration `2026_08_09_000002`). Rows enriched before sources wrote it, or
   never re-enriched since, stay `none` regardless of what matched.
2. **Filename titles poison the search.** 4,221 titles were "Title - Artist"
   (`FileTagger.php:281`). MusicBrainz searches `recording:"Gold - Imagine
   Dragons"` and finds nothing. The `cleanTitle` mangling in §4.2 adds more.
3. **The recording id is probably never read** from Picard-tagged files
   (`MUSICBRAINZ_TRACKID`), so the one path that is unambiguous is skipped.
4. **Rate limiting.** No throttle; MusicBrainz 503s return `null`, recorded as
   no match, not cached, not retried.
5. **No AcoustID key** (Handoff.md), so untagged files can never be
   fingerprinted. Last.fm and Spotify never set confidence at all.

Phase 3 fixes all five. The measure of success is the share of music at
`Exact` + `Fuzzy` after Phase 8, reported per cause.

---

## 5. Why the current design produces these bugs

The individual bugs are fixable one at a time, and Phase 1 does that for the
dangerous ones. But most of them are symptoms of five structural choices, and
fixing symptoms without the structure means the next feature reintroduces them.

1. **One job does everything.** Identify, tidy, normalise, embed, file — in
   one `handle()`. A failure anywhere marks the whole item `failed`; a success
   anywhere cannot be resumed from. There is no record of which step an item
   reached.
2. **Paths are strings.** Every "is this the same file" question is answered
   by comparing text, on filesystems where different text can be the same file.
3. **Identity is a by-product of filling fields.** Sources fill blanks in
   priority order and confidence is whatever the last one to run decided. There
   is no moment where the system chooses *which thing this is* from scored
   candidates, so there is nothing to show a reviewer and nothing to gate on.
4. **One row is one file is one work.** With no layer for "the same film in a
   different cut or quality", every second copy is a duplicate and every
   duplicate is a delete candidate.
5. **Review is a status, not a record.** Five columns and a reports table, each
   added for one feature. A new failure kind has nowhere to go, so it goes
   nowhere.

The design in §6 replaces each of these.

---

## 6. The design

### 6.1 Principles

- **Plan, then act.** Every destructive or moving step computes what it would
  do, records it, and only then does it. The plan is what dry-run shows and
  what undo reverses.
- **Identify once, explicitly.** A dedicated stage chooses an identity from
  scored candidates and records the score, the evidence and the runners-up.
  Enrichment fills fields *for* that identity; it never decides it.
- **Files by inode, not by name.** Every same-file question uses
  `realpath` + `stat` (device + inode); every same-content question re-hashes.
- **Every outcome is named.** Each stage returns `done`, `skipped`,
  `needs_review(reason)`, `retry(error)` or `failed(error)` — never `null`.
- **Default to doing nothing** (unchanged from AGENTS.md rule 2).

### 6.2 Data model changes

`media_items` stays the playable unit — clients, plays, playlists, ratings and
the API all key on it, and changing that is a rewrite of every client. What
changes is what sits around it.

**On `media_items`** (new columns)

| Column | Type | Purpose |
|---|---|---|
| `pipeline_stage` | string enum | Last stage completed (§6.3) |
| `pipeline_state` | `queued \| running \| waiting \| done \| failed` | Within that stage |
| `pipeline_attempts` | int | Retries of the current stage |
| `pipeline_error` | text null | Last error, for the sweeper and the UI |
| `pipeline_updated_at` | timestamp | Stuck detection |
| `work_key` | string null, indexed | The identity, e.g. `tmdb:movie:308266`, `mb:recording:<uuid>`, `tmdb:tv:1396:s01e02`, `isbn:978…` |
| `edition` | string null | `theatrical`, `directors_cut`, `extended`, `remaster_2011`, `live`, `deluxe`… |
| `quality_tier` | smallint null | Derived from the probe and the quality profile (§6.7) |
| `quality_score` | smallint null | 0–100, from quality checks |
| `is_primary_version` | bool | The version clients play by default |
| `locked_fields` | json | Field names a person edited (§6.5) |

`processing_status` remains as the visibility summary clients already read,
derived from the pipeline: `done` at `published` → `complete`; any open review
item → `needs_review`; `failed` → `failed`; anything else → `processing`.

**New tables**

```
media_probes            1:1 media_items
  media_item_id, probed_at, container, duration_ms, bitrate, size_bytes,
  video_codec, width, height, fps, hdr (none|hdr10|hdr10plus|dv|hlg), bit_depth,
  audio_streams json [{codec, channels, layout, language, bitrate, sample_rate, bit_depth, default}],
  subtitle_streams json [{codec, language, forced, default}],
  raw json            -- the full ffprobe output, for anything not yet a column

match_candidates        n:1 media_items
  media_item_id, source, external_id, work_key, title, subtitle, year,
  score (0–100), evidence json {title, duration, year, track_count, artist, id},
  chosen bool, created_at

quality_findings        n:1 media_items
  media_item_id, check, severity (info|warn|bad), value, threshold, detail json, created_at

file_moves              journal
  id, media_item_id, batch_id, kind (move|copy|delete|rename_case|archive),
  from_path, to_path, from_device, from_inode, size, hash_before, hash_after,
  state (planned|started|done|undone|failed), error, created_at, completed_at

review_items            replaces the spread in §3
  id, media_item_id, reason (enum, §6.9), source (system|user),
  user_id, profile_id, note,
  details json, suggested_action json,
  status (open|resolved|dismissed), resolution json, resolved_by,
  created_at, resolved_at
  unique open (media_item_id, reason) -- one open item per reason per file
```

`media_item_reports` is migrated into `review_items` with `source = user`.
`duplicate_status`, `needs_cover_review` and `enrichment_report.review_reason`
become review items with system reasons; the columns stay read-only for one
release and are then dropped.

**A field's provenance.** `MetadataVersion` already snapshots before each run.
Add `field_sources` json on each type-metadata table: `{"album": "musicbrainz",
"year": "manual", …}`. It is what makes locks, re-identify and the "Why?" modal
exact rather than reconstructed.

### 6.3 The pipeline

One job class per stage, chained with `Bus::chain`, each idempotent: running a
stage twice produces the same state as once. Each stage reads
`pipeline_stage`, does its work, writes the next stage in the same transaction
as its results, and dispatches the next job. A crash anywhere resumes at the
stage that did not commit.

```
 discover ─► catalogue ─► probe ─► hash ─► identify ─► enrich ─► dedupe ─► quality ─► plan ─► file ─► publish
                                                │                   │          │         │       │
                                                ▼                   ▼          ▼         ▼       ▼
                                            Needs Review (one review_item per reason, pipeline_state = waiting)
```

| Stage | Does | Gate to continue | On failure |
|---|---|---|---|
| **discover** | Walk watch folders (not the whole private disk). Size *and* mtime stable for `settle_seconds` across two passes. Skip `sample`, `trailer`, `featurette`, `extras/`, `.part`, `.!qB`, `._*`. Group sidecars with their media file by stem. | File settled | Retry next scan |
| **catalogue** | Create the row in the same transaction as `pipeline_stage = catalogue`. Record path, device, inode, size, sidecars. | — | Row not created; next scan retries |
| **probe** | ffprobe → `media_probes`. getID3 tags for music, NFO for video, OPF/embedded metadata for books. Classify from streams, not only extension. | Probe succeeded | `review: unreadable` |
| **hash** | xxh128, on its own `io` queue so a remux does not block the scan. | — | Retry |
| **identify** | §6.4. Gather evidence, query identity sources, score candidates, store top 5 in `match_candidates`, choose one, set `work_key`, `match_confidence`, `edition`. | `Exact` → continue. `Fuzzy` → continue but not past `plan` unless the type's policy allows (§6.8). `None` or ambiguous → review. | Provider error → `retry` with backoff, never `none` |
| **enrich** | Fill fields for the chosen identity from enrichment sources. Respect locks. Fetch artwork. | — | Per-source errors recorded; never blocks |
| **dedupe** | §6.6, with the identity now known. | Not a duplicate, or a version | `review: duplicate` |
| **quality** | §6.7. Cheap checks inline; the decode scan as its own low-priority job that can complete after `publish`. | No `bad` finding | `review: quality` |
| **plan** | Compute target path from the type's template; validate (§6.8); write `file_moves` rows in `planned` state. | Valid plan, confidence gate passed | `review: cannot_file` with the reason |
| **file** | Execute the plan (§6.8). Write tags / embed cover *before* the move, in the inbox, and re-hash after. | All moves `done` | `review: move_failed`; journal shows exactly which half happened |
| **publish** | `processing_status = complete`, fire `MediaItemAdded`, availability, subtitles index. | — | — |

**The sweeper** — a scheduled `library:pipeline-sweep` every 5 minutes:
- `running` and `pipeline_updated_at` older than the stage's timeout → back to
  `queued`, attempt +1.
- `queued` with no job in the queue → dispatch.
- `failed` with attempts < max and a retryable error → dispatch with backoff.
- Attempts exhausted → `review: stuck`, with the error.
- Every `file_moves` row in `started` → reconcile against the disk (§6.8).

This replaces the release-all-reservations-on-boot hack
(`AppServiceProvider.php:119-157`) and makes more than one worker safe.

**Queues.** Three: `io` (hash, file, decode scan), `cpu` (probe, fingerprint),
`net` (identify, enrich). The `net` queue runs through per-provider rate
limiters (§6.4), so throttling is a property of the provider, not of whichever
command dispatched the work.

**One entry point.** `LibraryIngest::accept(string $path, IngestOrigin $origin)`
is the only way to create a row. The scanner, `library:import-music`,
`CreateMusic`, the `ListMusic` and `BulkUpload` uploads, CSV import and the
transfer receiver all call it. `BulkUpload` passes `settled: true` because the
upload has completed.

### 6.4 Identification

**Provider roles.** Each source declares what it can do, replacing the single
`enrich()` contract:

```php
interface MetadataSource
{
    public function key(): string;                       // 'musicbrainz'
    public function name(): string;
    public function roles(): array;                      // [Role::Identify, Role::Enrich, Role::Artwork]
    public function types(): array;                      // [MediaItemType::Music]
    public function requiredSettings(): array;           // key => label, read by Integrations
    public function rateLimit(): RateLimit;              // e.g. 1/s for MusicBrainz
    public function candidates(Evidence $e): CandidateResult;   // Identify role
    public function enrich(MediaItem $i, Identity $id, FieldWriter $w): SourceOutcome; // Enrich role
}

enum SourceOutcome { Matched; NoMatch; Skipped; Error; RateLimited }
```

Identity sources (decide what a thing is): MusicBrainz + AcoustID for music,
TMDB for films and TV (TVDB as a second opinion for episode ordering),
OpenLibrary for books. Enrichment sources (add to a known thing, never decide
it): Last.fm, Spotify, Deezer, iTunes, Fanart.tv, Cover Art Archive, LRCLIB.
This is where user integrations plug in cleanly: adding Spotify can improve
artwork and audio features, and can never change which song a file is.

**Evidence, strongest first.** Collected once, before any network call:

1. Ids in the file: MBIDs (all Picard spellings — `MUSICBRAINZ_TRACKID`,
   `MusicBrainz Track Id`, UFID `http://musicbrainz.org`), ISRC, `.nfo`
   `<uniqueid>`/`<imdbid>`, OPF ISBN.
2. Ids in the path: `{tmdb-123}`, `[tmdbid-123]`, `{imdb-tt…}`, `{tvdb-…}`,
   `[mbid-…]`.
3. Acoustic fingerprint (fpcalc → AcoustID).
4. Parsed name: title, year, edition, season/episode, track/disc, from the
   filename *and* the folder chain (`Artist/Album (Year)/01 Title`).
5. Embedded text tags.
6. Probe facts: duration, track count in the folder, resolution.

**Scoring.** Every candidate gets a 0–100 score from weighted agreement:

| Signal | Music | Film | Episode |
|---|---|---|---|
| Id match (MBID, ISRC, TMDB, IMDb) | 100, stop | 100, stop | 100, stop |
| Fingerprint score ≥ 0.9 and duration within 3 s | 95 | — | — |
| Title similarity (normalised, Jaro-Winkler) | 35 | 45 | 25 |
| Artist / year agreement | 25 | 30 | — |
| Duration within 3 s / runtime within 5 min | 25 | 15 | 10 |
| Album and track-count agreement (folder) | 15 | — | — |
| Series + season + episode agreement | — | — | 65 |
| Provider's own relevance | 0–10 | 0–10 | 0–10 |

- **≥ 90 and a margin of ≥ 10 over the runner-up → `Exact`.**
- **70–89, or ≥ 90 with a narrow margin → `Fuzzy`.**
- **< 70 → `None`, review with candidates.**

The thresholds are config, and Phase 3 tunes them against a labelled sample of
the real library (§9.2) before anything moves on them.

Normalisation is shared and unit-tested: Unicode NFKC, case-fold,
transliteration for comparison only (never stored), `feat.`/`ft.` unified,
bracketed suffixes split off into `edition` rather than discarded,
punctuation removed for comparison only. The iTunes `''` bug cannot recur
because the shared normaliser refuses to compare two empty strings.

**Editions.** Parsed from the name (`Director's Cut`, `Extended`, `Unrated`,
`IMAX`, `Remastered 2011`, `Live`, `Deluxe`, `Acoustic`) and from the chosen
release (MusicBrainz release disambiguation and secondary types). Stored in
`edition`, never stripped and lost.

**Rate limits.** `Illuminate\Support\Facades\RateLimiter` keyed by provider,
shared across workers: MusicBrainz 1/s (their stated limit), AcoustID 3/s,
TMDB 40/10 s, Last.fm 5/s. A 429 or 503 is `SourceOutcome::RateLimited`; the
stage releases itself with the provider's `Retry-After` and never records "no
match". The one MusicBrainz client (`ArtistProfiles` merges into it) and the
one iTunes/Deezer client (`CoverArtFetcher` merges into the sources) share the
limiter.

**Caching.** `LookupCache` stays, gains TMDB and AcoustID, and stops caching
empty answers for less than a day — a "no match" from a misparsed title should
be retried after the parser is fixed.

### 6.5 Field merge and locks

- A `FieldWriter` is the only way a source writes a field. It checks
  `locked_fields`, records `field_sources`, and applies the policy.
- **Policy per field**, in `config/metadata_sources.php`: an ordered list of
  sources, e.g. `'artwork' => ['local', 'coverartarchive', 'fanart', 'tmdb',
  'itunes']`, `'title' => ['manual', 'identity']`. A higher-ranked source
  replaces a lower-ranked one's value; nothing replaces `manual`.
- Saving a field from any edit form adds it to `locked_fields`. A lock icon on
  the form clears it.
- **Re-identify** (from the review queue or the edit page) clears every
  unlocked field whose source is not `manual` and runs `identify` + `enrich`
  again — the fix for "corrected the MBID, kept the wrong album".
- `MetadataHistory::restore` restores people and credits too.
- `promoteTitle` goes through the writer like everything else, which fixes the
  overwritten-user-title bug and, with §6.4, the always-Exact bug: confidence
  is decided in `identify`, before any field is written.

### 6.6 Duplicates and versions

Run in `dedupe`, after `identify`, and as a nightly scheduled sweep.

| Same work_key? | Same edition? | Same quality tier? | Verdict |
|---|---|---|---|
| — (same bytes, different inode) | | | **Identical copy** — auto-resolvable when `duplicate_action = auto` |
| — (same inode) | | | **Same file, two rows** — merge rows, never touch disk |
| yes | yes | yes | **Duplicate** — review, keep-best suggested |
| yes | yes | no | **Upgrade / downgrade** — a version; review only if the profile says to keep one |
| yes | no | — | **Version** (different cut or release) — grouped, never flagged |
| no, but fuzzy title + duration | | | **Possible duplicate** — review, never auto, never bulk-merged |

For music, `work_key` is the recording; the release is the edition. The same
recording on the album and on a greatest-hits compilation is a version shown
as "also on", not a duplicate — the `Likely` pass in `DuplicateMatch` exists
because this case was being missed, and the version model is its proper home.

**Keep-best** uses the quality profile (§6.7), not file size: compare
`quality_tier`, then `quality_score`, then the profile's tie-breakers. The
existing `decideKeeper()` margins stay as the last tie-breaker only.

**Merge**, in one transaction:
1. Re-verify (unchanged rule): re-hash both, compare inodes, refuse on mismatch.
2. Re-point `media_plays`, ratings, playlist entries (keeping `sort_order`,
   de-duplicating within a playlist), watchlist, reading progress and
   collections to the survivor.
3. Journal the delete in `file_moves` and move the file to
   `media/.trash/<date>/…` rather than `unlink` it. A scheduled purge empties
   trash after `trash_days` (default 30). Undo restores from trash.
4. Mark the loser `merged` with `duplicate_of_id`. `resolveKeeping` updates the
   loser's row in every branch.

`duplicate_action = report` is checked by every merge path, UI and CLI.
`DuplicateDetected` fires once per pair, not once per sweep. The original is
chosen among *all* rows in the group including the one being checked.

### 6.7 Quality checks and the quality profile

**The profile** — one per media type, editable on the Library settings page,
with sensible defaults:

```
video: source   remux > bluray > web-dl > webrip > hdtv > dvd > unknown > telesync > cam
       res      2160 > 1080 > 720 > 576 > 480
       hdr      dv > hdr10plus > hdr10 > hlg > sdr
       codec    av1 = hevc > h264 > older
music: lossless 24-bit > 16-bit > lossy
       lossy    opus/aac ≥ 256 > mp3 320 > mp3 v0 > ≥ 192 > below
books: epub > azw3 > pdf (text) > pdf (scanned) > cbz
```

`quality_tier` is computed from the probe; `quality_score` starts at 100 and
loses points per finding.

**Checks**, cheapest first. Each writes `quality_findings`; any `bad` opens
`review: quality`.

| Check | How | Severity |
|---|---|---|
| No video stream in a film / no audio stream | probe | bad |
| Duration ≥ 5% short of the identified runtime / track length | probe vs identity | bad (truncated) |
| CAM / TS / TELESYNC / HDCAM in the name | parser keeps the marker | bad |
| Bitrate below floor for resolution (e.g. 1080p < 2 Mb/s) | probe | warn |
| Audio language not in the user's preferred list | probe | warn |
| Sample-rate upsampling (44.1 k content in 96 k FLAC) | spectral energy above 22 kHz | warn |
| Fake lossless (lossless container, lossy spectrum) | `ffmpeg -af showspectrumpic` or `sox stat`; energy cut-off ≤ 16–19 kHz | bad |
| Clipping | `astats` peak count | warn |
| Long silence (> 10 s inside a track) | `silencedetect` | warn |
| Interlaced | `idet` over 500 frames | warn |
| Decode errors | `ffmpeg -v error -i f -f null -`, low priority, `io` queue | bad |
| Transcode output | duration and stream count compared with the source before the original is archived | bad |

The decode scan is the expensive one (real-time-ish for video). It runs as a
background job after `publish`, so a good file is in the library at once and
a bad one moves to review when the scan finds it.

### 6.8 Filing

**Templates**, editable per type on the Library settings page, with tokens and
a live preview against real items:

```
music  {albumartist}/{album} ({year})/{disc>1:Disc {disc}/}{track:00} {title}
film   {title} ({year}){edition: {{edition-{edition}}}}/{title} ({year}){edition: - {edition}} [{quality}]
tv     {series} ({series_year})/Season {season:00}/{series} - S{season:00}E{episode:00} - {episode_title}
book   {author}/{title} ({year})
```

Album artist, not track artist, so compilations stay together. Disc folders
only for multi-disc releases, which fixes the "(2)" collisions. The
`{edition-…}` form is the one Plex, Jellyfin and Emby already read.

**Confidence gate, for every type.** A per-type policy, default:

| | Exact | Fuzzy | None |
|---|---|---|---|
| music | file | file only if the tags already agreed with the match before enrichment | stay, review |
| film, tv, book | file | stay, review | stay, review |

Music keeps a looser rule than video, which is the real point of the old
exemption — embedded tags are good evidence — but the evidence must be the
file's own tags, not the API's.

**Plan validation**, before anything moves:
- Source and target compared by `realpath` and `stat` (device + inode). Same
  inode with a different spelling → a case-only rename via a temporary name
  (`a.mp3` → `a.mp3.sc-tmp` → `A.mp3`), never "adopt and delete".
- Target exists with a different inode → if byte-identical, journal and trash
  the source; otherwise suffix. Suffix and rename under a per-directory lock
  (`Cache::lock("file:{dir}")`) so two workers cannot race; `link()` + `unlink()`
  instead of `rename()` where the filesystem supports it, so the target is
  never silently replaced.
- Segment sanitising: control characters, `/\:*?"<>|`, trailing dots and
  spaces, Windows reserved names (`CON`, `NUL`, `COM1`…), truncation by
  **bytes** (255) not characters, re-trimmed after truncation, `(Year)`
  preserved.
- Whole path under the platform limit (260 on Windows without long paths,
  1024 macOS, 4096 Linux).
- Sidecars (`.srt`, `.ass`, `.vtt`, `.lrc`, `.nfo`, `.cue`, `poster.*`,
  `folder.*`, `fanart.*`) planned alongside, renamed to the new stem with
  their language suffixes kept.

**Execution.** For each planned `file_moves` row: mark `started` → move → verify
(same volume: inode unchanged; cross volume: size **and** xxh128 of the copy)
→ update `file_path`, clear `content_hash`, re-point peers (rule 2) → mark
`done`, all but the move itself in one transaction. Cross-volume: copy to
`target.sc-partial`, verify, rename into place, then unlink the source and
check the result.

**Recovery.** The sweeper reconciles any `started` row: if the target exists
with the expected hash and the source is gone → finish the DB half; if the
source exists and the target is missing or partial → remove the partial, mark
`planned`, retry; if both exist and match → trash the source, finish. No state
is ambiguous because the journal recorded the inode and hash before starting.

**Undo.** `library:undo-moves --batch=<id>` / `--item=<id>` / `--since=<time>`,
and a button on the item. Reverses `done` rows in reverse order through the
same validated executor.

**Dry run.** `library:organize` previews every type (today it is music only)
from the same planner the pipeline uses. Auto-organize reads
`LibrarySettings::autoOrganize()`.

**Writing tags.** Music tags (title, artist, album artist, album, track/disc,
year, MBIDs, ISRC, cover) are written in `file`, before the move, only for
`Exact` matches, only if `write_tags` is on (default off for the first
release), and only when the values differ from what is already in the file —
so re-enrichment no longer rewrites every file. The hash is recomputed after.

### 6.9 The review queue

**Reasons**, one enum for system and user:

| Reason | Source | Opened by | Resolution actions |
|---|---|---|---|
| `no_match` | system | identify, score < 70 | Search, pick candidate, enter id, mark as unidentifiable (keep, don't file) |
| `ambiguous_match` | system | identify, narrow margin | Pick candidate, search |
| `low_confidence` | system | identify, `Fuzzy` + type policy | Confirm, pick other, search |
| `compilation_or_undated` | system | identify (MusicBrainz) | Confirm, pick release |
| `duplicate` | system / user | dedupe, user report | Compare side by side, keep one / keep all as versions / not a duplicate |
| `quality` | system | quality | Accept, replace (opens upload for this item), delete to trash |
| `unreadable` | system | probe | Rescan, delete to trash |
| `cannot_file` | system | plan | Fix metadata, edit target, keep in place |
| `move_failed` | system | file | Retry, undo, keep in place |
| `missing_file` | system | sweep | Locate (path picker), remove row |
| `stuck` | system | sweeper | Retry, view error |
| `wrong_metadata` | user | report | Re-identify, edit, pick candidate |
| `wrong_cover` | user | report, merge | Pick artwork from sources, upload |
| `file_problem` | user | report | Runs quality checks now, then as `quality` |
| `other` | user | report | Note shown; any action |

User reasons keep the five labels in `ReviewReason` that clients already show;
the enum gains the system cases, and `label()`/`hint()` stay the client-facing
wording.

**The page.** One Needs Review page with tabs by group (Identify, Duplicates,
Quality, Files, Reports), each row showing the reason, the evidence and the
suggested action. A candidate picker shows the stored `match_candidates` with
their scores and lets a search run against any identity source inline.
Resolving writes `resolution`, closes the item, and puts the media item back
into the pipeline at the stage that resolution implies — picking a candidate
resumes at `enrich`, accepting a quality finding at `plan`. The badge counts
items, not reasons.

**The endpoint.** `POST /api/v1/items/{item}/review` creates a `review_items`
row with `source = user`, fires `MediaItemReviewFlagged`, is throttled
(10/min per user), and is profile-aware: a kid profile's report goes to the
queue but **does not hide the item**; an admin's or owner's does. Unknown and
hidden ids both return 404.

**Re-enrich** closes `wrong_metadata` reports it resolves; "Looks fine" is
available for every reason including `failed`/`stuck`; Kept duplicates leave
the queue.

### 6.10 Integrations

- The Integrations page is built from every registered source's
  `requiredSettings()` — core and plugin — grouped by role, with an on/off
  toggle and a drag-to-order priority per type. `musicbrainz_enabled` and
  `openlibrary_enabled` start gating something.
- Plugin sources: `Registry::metadataSource()` honours its priority argument
  and de-duplicates by `key()`.
- **One Spotify credential pair.** The core Integrations `spotify_client_secret`
  and the Playlist Porter's `spotify.client_id`/`spotify.client_secret`
  collapse into one `spotify.*` pair owned by core, read by both. Drop the
  deprecated audio-features call (or keep it behind a capability check for apps
  that still have access); search with artist + title + duration and use the
  shared scorer.
- Add Cover Art Archive as the first music artwork source (release MBIDs are
  already stored) and Fanart.tv for film/TV.
- A **Test** button per source that runs a known lookup and shows the outcome,
  including rate-limit and auth errors — so "8,440 no match because the key is
  missing" is visible on day one.

### 6.11 Parser fixes

Unit-tested against a table of real filenames (§9.1):

- Leading-number strip only when followed by a separator *and* the folder
  looks like an album (other files numbered sequentially), never on a bare
  "7 Rings".
- Keep dots inside words (`Mr.`, `Dr.`, `St.`, `U.S.A.`); split on dots only
  where dots are the separator throughout the name.
- Release markers matched only after the year or in bracketed groups, so "A
  Complete Unknown" survives. Year = the last plausible 19xx/20xx before the
  first marker, not the first.
- Editions captured into `edition`.
- `isMultiEpisode` uses `withoutExtension`; separators `[._ -]` instead of
  `\b`; `S01E01E02`, `S01E01-E02`, `S01E01-02`; specials (`S00E05`, `Special`);
  date-based (`2024.03.15`); absolute numbering (`- 103`) with a series hint.
- Path ids (`{tmdb-…}` etc.) extracted and stripped.
- `FileTagger` reads every Picard MBID spelling, album artist, release-group,
  compilation, totals, AcoustID id; promotes the embedded title whenever it is
  present and the stored title came from the filename (`field_sources.title ==
  filename`), not only when it equals the raw filename.

---

## 7. Rollout — phases, issues and acceptance

Each bullet is one tracker item and, usually, one PR with its changelog entry.
Every phase states what "done" means; "done" is verified against a copy of the
real library (§9.3), not only tests.

### Phase 0 — Before anything (same day)

- Take a `db:backup`.
- Set `LIBRARY_AUTO_ORGANIZE=false` in `.env` (then restart the workers) until
  Phase 1 ships. The UI toggle does not work (§4.1.5); the job reads only the
  config value.
- Set duplicate handling to **Report** on the Library settings page. (This one
  *is* read from settings, `LibrarySettings::duplicateAction()`; the default is
  already `review`, so this only matters if it was changed to `auto`.)

### Phase 1 — Stop the data-loss paths

1. Organizer: compare by `realpath` + inode; case-only rename via temporary
   name; never `adoptExisting` the same inode. Test on a case-insensitive APFS
   volume (macOS default) and a symlinked root. (§4.1.1)
2. `DuplicateDetector::merge`: same inode check. (§4.1.1)
3. `ConversionFiler`: refuse when the filed path exists or equals the
   original; collision-check the archive path; clear `content_hash`. (§4.1.2)
4. TMDB: record confidence before `promoteTitle`, against the *parsed* title.
   One-off command to re-score every TMDB `Exact` match whose `matched_by` is a
   title search; downgrade to `Fuzzy` where the parsed title disagrees.
   (§4.1.3)
5. MusicBrainz: `Exact` only when the recording was actually reached by MBID or
   ISRC. (§4.1.4)
6. Auto-organize reads `LibrarySettings`. (§4.1.5)
7. Music confidence gate: `Fuzzy` music files only when the file's own tags
   agreed; nothing in `needs_review` moves. (§4.1.6)
8. `resolveKeeping` updates the original's row in every branch; `pickOriginal`
   includes the item itself; bulk merge refuses `Likely`/`SameTitle`;
   `duplicate_action=report` enforced everywhere. (§4.1.7–4.1.10)
9. Organizer: check the source `unlink`; hash-verify cross-volume copies;
   per-directory lock around suffix + rename; fail instead of returning an
   occupied path. (§4.1.11)
10. `CoverEmbedder`: skip when the embedded cover already matches; clear the
    hash after writing. (§4.1.12)
11. Deletes go to `media/.trash/` instead of `unlink`. (§6.6)

**Done when:** each fix has a test that failed before it; a full `library:organize
--move` against the library copy (§9.3) loses zero files (count and total
hash of every media file before = after, minus journalled trash); auto-organize
can be turned off from the UI.

### Phase 2 — A real pipeline

1. Pipeline columns + `pipeline_stage` enum; stage jobs; `Bus::chain`.
2. `LibraryIngest::accept()`; route all five import paths through it.
3. `file_moves` journal + executor + reconcile + `library:undo-moves`.
4. `library:pipeline-sweep` scheduled every 5 min; remove
   release-all-on-boot.
5. Series rows get identified (the series is itself an item in the pipeline).
6. Dedupe after identify; nightly duplicate sweep scheduled.
7. Three queues (`io`, `cpu`, `net`); worker supervisor config for each in the
   bundled runtime and the launchd plists.
8. Discover: watch folders only; size+mtime settle; junk exclusion; sidecar
   grouping; subtitles imported before filing.

**Done when:** killing the worker at a random point 200 times during an import
of the test corpus (§9.4) leaves every file accounted for and every item
either filed or in review after the sweeper runs; no item is `pending` or
`processing` for more than the sweep interval plus a stage timeout.

### Phase 3 — Identification that matches

1. `MetadataSource` v2 contract (roles, outcomes, rate limits); adapt the nine
   sources; core sources first, plugin contract kept compatible with a shim.
2. Per-provider rate limiter; `RateLimited` / `Error` outcomes retry, never
   "no match".
3. Evidence collection; FileTagger MBID spellings and missing tags; NFO; path
   ids.
4. Shared normaliser + scorer; `match_candidates`; thresholds in config.
5. `FieldWriter`, `field_sources`, `locked_fields`; titles through the writer.
6. Re-identify action.
7. Parser fixes (§6.11).
8. Integrations page from `requiredSettings()`; toggles; Test button; one
   Spotify credential pair; Cover Art Archive.
9. `music:reenrich` rebuilt on the pipeline (fixes the enum compare, the
   `--sample` crash and the missing snapshot).
10. Backfill `match_confidence` for rows enriched before the column existed.

**Done when:** on the labelled sample (§9.2), `Exact` precision ≥ 99% and
`Fuzzy` precision ≥ 90%; the share of music at `none` is reported per cause
from §4.4 and each cause's count has dropped to what remains genuinely
unidentifiable; no 429/503 is ever recorded as no match.

### Phase 4 — Know what each file is

1. `media_probes` from ffprobe for every type; backfill command.
2. Quality profile + `quality_tier`.
3. Cheap quality checks inline; decode scan as a background job.
4. Fake-lossless and upsampling detection for music.
5. Transcode output verified before archiving the original.

**Done when:** every item has a probe row; a seeded corpus of known-bad files
(truncated, CAM, no audio, MP3-in-FLAC, upsampled, corrupt) is flagged 100%,
and the known-good corpus produces no `bad` finding.

### Phase 5 — Versions, not duplicates

1. `work_key`, `edition`, `is_primary_version`; backfill from existing ids.
2. Dedupe by the §6.6 table; keep-best by profile.
3. Merge re-points plays, ratings, playlists (order kept), watchlist,
   progress, collections; trash, not unlink.
4. Versions shown in the item page and the API (`versions[]`), clients play the
   primary and offer the others. iOS picks this up in its next minor.
5. `MergeDuplicatePlaylists` uses unscoped tracks and keeps order.

**Done when:** a 4K and a 1080p copy of one film, a theatrical and a
director's cut, and an album and compilation copy of one recording are all
versions and none is in the duplicate queue; a true duplicate merge keeps
every play and playlist entry.

### Phase 6 — Filing v2

1. Templates with tokens and preview; album artist; disc folders; editions.
2. Planner validation (§6.8): reserved names, byte-length, path length.
3. Sidecars moved with media.
4. Tag writing (opt-in).
5. `library:organize` previews and files every type.

**Done when:** the full library copy files with zero collisions that are not
true duplicates, zero orphaned sidecars, and `library:undo-moves --batch`
restores the tree byte-for-byte.

### Phase 7 — One review queue

1. `review_items` + reason enum; migrate `media_item_reports`, duplicate,
   cover and enrichment-report state.
2. Every stage opens review items through one service; the §4.3 table is
   empty.
3. Needs Review page v2: groups, evidence, candidate picker with inline
   search, per-reason actions, re-entry at the right stage.
4. Endpoint: throttle, profile rules, event, 404s.
5. Old columns read-only for one release, then dropped.

**Done when:** a query for items hidden from the library with no open review
item returns zero rows, run after every phase from here on as a health check
in `server:health`.

### Phase 8 — Re-process the existing library

See §8.

---

## 8. Re-processing the existing library

8,335 items are already catalogued and filed by the old rules. Re-running the
pipeline over them is the point, and also the riskiest single operation in
this plan.

1. **Backup.** `db:backup`, plus a manifest of every media file: path, size,
   xxh128. Kept outside `storage/`.
2. **Dry run, everything.** `library:reprocess --dry-run` runs identify,
   dedupe, quality and plan for every item and writes a report: matches that
   would change, files that would move (old → new), duplicates and versions
   found, quality findings, review items that would open. No row or file
   changes. Reviewed by a person before step 3.
3. **Identify only.** Apply new identities and fields, file nothing. Locked and
   `reviewed_at` items keep their fields. Compare the §4.4 numbers.
4. **Review the review queue.** Clear what the dry run opened, especially
   `ambiguous_match` and `duplicate`.
5. **File in batches** of 500 with `--batch` ids, journalled, verifying the
   manifest after each: every file in the manifest exists at its old or new
   path with the same hash, or is in trash with a journal row.
6. **Undo window.** Trash is not purged and batches stay undoable for 30 days.

Never against the live DB while the server holds it open (Handoff.md): stop
the bundled runtime and workers first, or run against a copy and swap.

---

## 9. How we will know it works

### 9.1 Parser and normaliser tables

A fixture of real filenames → expected parse, kept in
`tests/Fixtures/filenames.php`, seeded from the library's own unmatched titles
and every case in §4.2. Every parser bug fix adds its row.

### 9.2 A labelled sample

500 items sampled across types from the real library, identity confirmed by
hand once, stored as `tests/Fixtures/labelled-sample.json` (ids only, never
media). The identify stage is scored against it in CI with recorded provider
responses, and the thresholds in §6.4 are tuned on it. Precision and recall
per type are printed in the PR that changes scoring.

### 9.3 A library copy

A copy-on-write clone (`cp -c` on APFS) of the media tree and the DB, for every
phase's "done when". Never the live library.

### 9.4 Fault injection

- Kill the worker at random points during import (Phase 2).
- Fill the target volume during a cross-volume move.
- Return 503 and 429 from each provider.
- Case-only renames and symlinked roots on APFS.
- Two workers filing the same target name.

### 9.5 End-to-end

One feature test that drops a mixed folder (album, multi-disc album,
compilation, film in two qualities, a director's cut, three episodes with
sidecars, a truncated file, a CAM rip, an MP3-in-FLAC, a byte duplicate, a
junk sample) into a watch folder and asserts the final tree, the rows, the
versions, the review items and the journal.

### 9.6 Health check

`server:health` gains: hidden-with-no-review count (must be 0), stuck-stage
count, journal rows not `done`/`undone`, provider error rate over 24 h.

---

## 10. Rule changes for AGENTS.md

Once Phase 1 merges, rule 2 changes:

- **"Music is exempt"** becomes: music may be filed at `Fuzzy` only when the
  file's own tags agreed with the match before enrichment. API data alone
  never moves a file.
- New: **compare files by inode, not by path string.** Any "is this the same
  file" check uses `realpath` + `stat`.
- New: **every move and delete goes through the `file_moves` journal**, and
  deletes go to trash.
- New: **a provider error is not a no-match.**

---

## 11. Decisions needed

1. **Merged duplicates' history** — re-point plays, ratings and playlist
   entries to the survivor (this plan's default), or drop them?
   (Open since LibraryCleanup.md.)
2. **Trash retention** — 30 days by default?
3. **Tag writing** — off by default for the first release, opt-in per library?
4. **`media/unsorted/Sunnify/…`** — a staging folder to be emptied once filed?
   It likely explains a large share of the 1,064 byte duplicates.
5. **Kid-profile reports** — queue without hiding (this plan), or not allowed?
6. **Spotify audio features** — drop, or keep for apps that still have access?
7. **Versions in clients** — iOS shows a version picker on the item page; is
   that wanted on every client, or server-side "play best" only?

---

## 12. Appendix — every finding, by file

Paths under `app/` unless stated.

**Services/LibraryOrganizer.php** — string path compare `:145`; adopt-and-delete
same inode `:170, :517`; music gate exemption `:70-77`; `rename` before save
crash window `:178→:224`; size-only copy verify `:195`; unchecked `unlink`
`:211`; `uniquePath` race and occupied fallback `:527-545`; `segment()` char not
byte truncation, no reserved names `:441-460`; fixed templates `:236-240`; no
disc folder, track artist folder; no sidecars; no journal.

**Services/ConversionFiler.php** — filed path can equal original `:218-234`;
`move()` no existence check `:262-291`; archive path no collision check;
`file_path` without clearing hash `:114-127, :163-166`.

**Services/DuplicateDetector.php** — runs before identify (scanner `:144`);
merge path compare `:627`; `resolveKeeping` orphans original `:716-731`;
`pickOriginal` excludes self `:579-583`; video keep-best by size `:865-891`;
music keep-best by size/duration `:977-1026`; re-flags pending every sweep.

**Filament/Resources/Duplicates/Tables/DuplicatesTable.php** — bulk merge of
`Likely`/`SameTitle` `:671`; `report` action not enforced; re-enrich leaves
report open `:388-393`; Looks fine only for `needs_review` `:324, :366`;
missing-album "Why?" `:421-437`.

**Filament/Resources/Duplicates/DuplicateResource.php** — badge double count
`:96-104`; stale docblock `:21-27`.

**Filament/Resources/Concerns/HasNeedsReviewTab.php** — Kept duplicates stay
in the tab `:53-57`.

**Jobs/EnrichMediaItemJob.php** — everything in one job `:53-103`;
auto-organize reads config `:385`; missing-album flag after report saved
`:296-328`; docblock promises an organize retry that is not scheduled `:381`.

**Jobs/DetectDuplicatesJob.php** — docblock promises a scheduled sweep that
does not exist; `tries=1`, 3600 s.

**Services/LibraryScanner.php** — whole private disk swept `:830-846`; series
never enriched `:339-353`; `cleanTitle` leading number `:718`; dots → spaces;
`stripReleaseTags` marker collisions, first year, editions discarded
`:750-784`; non-canonical recover keys `:410-415, :175, :464`; intake before
hash `:617 vs :144`.

**Services/EpisodeParser.php** — `isMultiEpisode` uses `PATHINFO_FILENAME`
`:171`; `\b` vs underscores; season 0 refused `:157`.

**Services/Metadata/Sources/Movie/Tmdb.php, Show/Tmdb.php,
Concerns/TalksToTmdb.php** — promote before confidence `:71/:76`, `:72/:77`,
`:234-235`; unconditional title overwrite `:275`, `:193`; no cache `:48`.

**Services/Metadata/Sources/Music/MusicBrainz.php** — ISRC-present ≠
ISRC-matched `:66-67`; earliest-dated pick `:261-290`; no throttle `:662`;
writes artist/album that drive filing `:404, :448`.

**Services/Metadata/Sources/Music/FileTagger.php** — MBID key spellings;
missing tags; Camelot `:471`; `looksLikeAFilename` `:289-327`.

**Services/Metadata/Sources/Music/AcoustId.php** — 0.5 threshold, `Exact` with
no check `:107, :148-153`.

**Services/Metadata/Sources/Music/ItunesSearch.php, Deezer.php,
Metadata/CoverArtFetcher.php** — empty-normalised artist matches anything
`:149-156`, `:126`; duplicate clients.

**Services/Metadata/Sources/Music/Spotify.php** — no client id field; deprecated
endpoint `:63`; `limit=1` `:99-105`; throws on error body; token per item `:46`.

**Services/Metadata/MetadataPipeline.php** — plugin priority ignored,
duplicates not de-duplicated `:184-190, :215`; source errors swallowed
`:60-66`.

**Services/MetadataHistory.php** — restore skips credits `:174-184, :253`.

**Services/Metadata/CoverEmbedder.php** — rewrites every run; hash not cleared.

**Console/Commands/ReenrichMusic.php** — enum vs string `:81`; `--sample`
throws `:90`; inline path skips snapshot/status/organizer.

**Console/Commands/ReclassifyTelevision.php** — no `showMetadata`; rescan
advice wrong `:106`.

**Console/Commands/OrganizeLibrary.php** — music only `:33`.

**Console/Commands/MergeDuplicatePlaylists.php** — scoped tracks, lost order
`:119-134`.

**Http/Controllers/Api/MediaController.php, Models/MediaItem.php** — report
endpoint: any profile hides any item, no throttle, 201/404 leak, no event
(`MediaController.php:145-170`, `MediaItem.php:112-131`).

**Models/MediaItemReport.php** — `dismiss()` never called `:83`.

**Providers/AppServiceProvider.php** — release-all-reservations on boot
`:119-157`.

**Services/Subtitles/SubtitleImporter.php** — glob too wide `:270`; queued after
the move.

**Filament/Pages/BulkUpload.php** — scans immediately (always unsettled);
`preserveFilenames` overwrite.

**Filament/Pages/Integrations.php** — hardcoded keys `:509-525`; no Spotify
client id `:515`; `requiredSettings()` unread.
