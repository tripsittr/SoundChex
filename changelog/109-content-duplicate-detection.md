# 109 — Same-recording duplicate detection for music

*2026-09-18.* · **Issue** S-257

## Done

Duplicate detection used to be **byte-identical only** — two files counted as
the same solely when every byte matched. That is safe, but it misses the common
case: the *same song acquired twice* at a different bitrate, in a different
format (MP3 vs FLAC vs M4A), or as a re-rip. Those never hash the same, so they
were never flagged — which is why a full library shows so few duplicates while
holding many.

Music now also gets a **content pass**, matched in descending confidence:

- **ISRC** — the recording's standard code
- **MusicBrainz recording id**
- **AcoustID** acoustic fingerprint
- **Tags fallback** — same normalised artist + title (+ album) with a length
  within a tolerance (default 2s), for the large majority of tracks that have no
  ID tags but do have artist/title tags.

### Review only — never auto-deleted

A byte-identical copy can be deleted automatically after a byte re-compare,
because nothing is lost. A content match is **two genuinely different files**, so
it is always flagged for review and never auto-deleted, even in "auto" mode. The
review screen gains a **"Keep one"** action: you pick which copy survives (with
each file's size shown) and the other is deleted — no byte re-compare, because
the files differ by definition and your choice is the gate. `Merge` stays the
byte-identical path and now refuses a content match rather than un-flagging it.

### Where it shows

- **Library → Duplicates** — a new **Match** column (Identical file / Same ISRC /
  Same MusicBrainz recording / Same audio fingerprint / Same track (tags +
  length)), the **Keep one** row action, and bulk-merge now skips content matches
  with a clear note.
- **Library settings** — a toggle "Also match the same recording in a different
  file (music)" and a length-tolerance field, under Duplicate detection.
- **`php artisan library:duplicates`** — lists the match reason; `--merge` deletes
  only byte-identical copies and reports how many same-recording matches were left
  for review.

## Worth knowing

- **Migration included and run here:** `add_duplicate_match_to_media_items` adds a
  nullable `duplicate_match` column (null = an older byte-only flag). No data
  rewrite.
- **Existing libraries need a re-scan** to pick up content duplicates —
  `php artisan library:duplicates`, or the scheduled `DetectDuplicatesJob`. On the
  dev library (8,313 tracks) this surfaced ~1,410 duplicate groups / ~1,573
  redundant copies that byte-hashing had caught almost none of.
- **On by default** (`detect_content_duplicates`), but only ever *flags* — the
  deletion action ("auto") still applies to byte-identical copies alone.
- Content matching is **music-only**; movies, TV, and books are unchanged
  (byte-identical detection only).

### Also: self-heal stale flags

A pending duplicate whose `duplicate_of_id` is null — flagged as a duplicate of
nothing — could never be resolved: Merge found no original and refused, which
surfaced as a confusing “1 skipped — contents differ or the original is missing”
on bulk-merge, and the row sat in the review list forever. Detection now clears
these orphans at the start of each pass (`DuplicateDetector::clearOrphans()`,
called by the job and the `library:duplicates` command) so they leave the list
and are judged afresh. Resolved decisions (Kept / Merged) are never touched.

## Still wrong / next

- AcoustID coverage in the dev library is 0 and ISRC/MusicBrainz are sparse, so
  the **tag fallback does the real work** today. Better ID coverage from the
  metadata pipeline would raise precision over time; fingerprinting unmatched
  files on scan is a candidate follow-up.
- The fuzzy pass compares within the whole catalogue in SQL per item; on a very
  large library the detection job is heavier than the pure-hash pass. It chunks,
  but has not been profiled on a 100k-track library.

## Tests

PHP: **616 passing** (+13). New `ContentDuplicateDetectorTest` covers each match
kind, the tolerance and album guards, the independent on/off switch, the
never-auto-delete rule, both keep-which-copy directions, and merge refusing a
content match. Existing `DuplicateDetectorTest` / `DuplicateKeeperTest` unchanged
and green.
