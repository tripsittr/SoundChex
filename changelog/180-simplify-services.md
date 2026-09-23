# 180 — A cleanup pass over `app/Services`

**Merged** 2026-09-23 · **Issues** S-353

Behaviour-preserving simplification of the whole service layer — 55 files,
~17,300 lines. Nothing about how the app behaves changes. Two latent defects
are closed along the way, and one is reported rather than fixed.

## What changed

### A constraint that was written down but not enforced

`TransferReceiver`'s docblock said its hash algorithm "has to match
DuplicateDetector exactly". Nothing made that true — both spelled `'xxh128'`
as a bare literal in four places between them. `DuplicateDetector::HASH` is now
the canonical constant and `TransferReceiver::HASH` derives from it, so the two
cannot drift.

Verified against real data: 25 stored hashes still match their files after the
change, so the algorithm is genuinely unchanged.

### One long-form dedup, already drifting

`LibraryScanner::scan()` and `::recover()` each carried a ~45-line block
deriving type, container probe, episode marker, title and seed. The copies had
already begun to diverge: `recover()`'s had lost its explanatory comments and
replaced one with "see the same decision in `scan()` above". Extracted to
`classify()`, keeping the full S-86 and 48-specials reasoning.

### `last_error` truncation is structural now

`TransferReceiver` wrote `last_error` from 15 places, several of them passing
raw `$e->getMessage()` or `explain()` output into a `varchar(255)` column. A
`truncate()` helper already existed and was used at nine of them — the other
six simply had to be remembered. `noteError()` and `failTransfer()` make it
unconditional.

### Eleven orphaned docblocks

A method's documentation sitting above a *different* method's docblock, so the
reasoning was attached to nothing — in `TransferReceiver`, `LibraryScanner`,
`AlbumBrowser`, `ArrServices`, `ContentGate`, `ConversionFiler`, `HostServices`,
`LibraryOrganizer` and `NetworkAddresses`. Given how much this codebase relies
on its comments, worth fixing across the directory.

### Smaller

`DuplicateDetector`'s duplicated artist SQL (`LOWER(TRIM(COALESCE(NULLIF(
primary_artist, ""), artist)))`, written twice and required to agree) extracted;
`maxValue()` deleted as a reimplementation of `max()`; `LibraryStatistics`'
`listened_seconds` fragment, repeated across six queries whose results are
compared against each other, made a constant; two dead methods removed from
`ArtistCredits`.

## Worth knowing

- **The reasoning comments survived.** Seven comment lines appear as removals
  in the diff; all seven are relocations, confirmed present afterwards. The
  only genuine loss is one duplicated `ffprobe` comment, which went from two
  copies to one along with the code it described.
- **Pint was not run**, for the same reason as last time: it flags 170+ files
  and would destroy `git blame`.
- Tests: **903 of 909**, the same two pre-existing failures as the baseline.

## Still wrong

- **S-355** — `MusicBrainz::fillBlank()` omits the `&& filled($value)` guard
  its two siblings have, so it would write an empty value over a blank field.
  Harmless today because both call sites filter empties first, but the safety
  lives at the call sites rather than in the method. Left for its own change
  and its own test rather than riding along with a refactor.
- `expandPath()` is still duplicated between `LibraryScanner` and
  `LibraryOrganizer`. Unifying it needs a new file or a coupling between the
  two; four well-understood lines did not justify either.
