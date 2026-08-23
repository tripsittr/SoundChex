# Library cleanup — duplicates, wrong types, and metadata that never matched

Covers **S-94** and **S-95**, both raised in GitHub #16: "there are tv shows in
the movie category. Additionally, the music is a huge mess of duplicates and I
untagged garbage."

## What is actually true

Measured against the live catalogue on 23 August, not estimated:

| | |
| --- | --- |
| music rows | 8,440 |
| film rows | 700 |
| show rows | 3 series, 572 episodes |
| **`content_hash` values appearing more than once** | **1,064** |
| titles appearing more than once | 1,722 |
| music rows with `match_confidence = none` | **8,440 — all of them** |
| music files catalogued as films | at least 3 |

Two of these are worth separating, because they call for opposite levels of
nerve.

**1,064 duplicate hashes is a fact.** Two rows, one byte-identical file. That
can be acted on.

**1,722 duplicate titles is a symptom**, and mostly a symptom of the third
line: nothing has ever matched a metadata source, so every title is a filename.
`recognize (feat. riverkinn)` and `recognize (feat riverkinn)` differ by a full
stop. Ten tracks are called `#`. Those are not necessarily duplicate *files* —
they are files nobody has ever identified, and a live album, a remaster and a
single can share a title honestly.

## The rule this follows

From AGENTS.md, rule 2: **verify before destroying, and default to doing
nothing.** `DuplicateDetector` already re-hashes both files immediately before
deleting one, because a hash recorded days ago may be stale. Anything here that
deletes must do the same.

The library is the user's own music. A wrong delete is not recoverable from a
backup nobody took.

## Steps

### 1. Why is audio typed as video (S-94)

Smallest and most diagnostic. `LibraryScanner::typeForExtension()` decides type
from the extension, so an `.mp3` cannot become a film that way — something else
is doing it, and until that is known the same thing may be miscategorising
more than three rows. Find the mechanism before changing anything.

### 2. Report the duplicates before touching them

A command that shows what is duplicated by hash, which copy it would keep and
which it would remove, and what that frees — and changes nothing. The rule
about a second deliberate act applies to 1,064 files far more than to 50.

Keep rule: the copy under `media/library/…` over one under
`media/unsorted/…`; failing that, the one catalogued first. A filed copy is the
one the organiser chose deliberately.

### 3. Remove them, re-verified

`--apply`, re-hashing both files immediately before removing either, and
refusing on any mismatch. Removes the row *and* the file, because a row without
its file is the orphan problem in S-45.

### 4. Metadata is a different problem

8,440 rows at `match_confidence = none` is not a cleanup, it is the metadata
pipeline never having run against this library, or having run and matched
nothing. Diagnose before proposing anything: the answer might be a missing API
key (S-12 says AcoustID, Spotify and OpenSubtitles have none), in which case no
amount of cleaning changes it.

**Not in scope here:** deleting anything on a title match, or "tidying" titles.
Both need the metadata question answered first, and both are irreversible
against files that are the only copy.

## Open questions for the issue

- After a duplicate is removed, should its `media_plays` and playlist entries
  be re-pointed at the surviving row, or is losing them acceptable? Re-pointing
  is more work and keeps history that rescanning cannot rebuild.
- `media/unsorted/Sunnify/…` looks like an import from elsewhere. Is that a
  staging folder that should be emptied once filed, which would explain a large
  share of the 1,064?
