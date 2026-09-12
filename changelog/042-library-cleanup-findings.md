# 042 — What the library cleanup actually needed

**Merged** 2026-09-12 · **Issues** S-95

The duplicate and metadata cleanup (S-95) was written when the library was
half-transferred and looked far worse than it is. With the files now present,
the real state turned out to be most of the way fixed already.

## What changed

### The duplicate cleanup is done — and there was nothing left to delete

S-95 described 644 redundant copies and 1,338 unhashable rows, on a machine
holding only ~918 of 9,737 files. All of that has moved on: 8,326 of 8,328
files are present, every row has a `content_hash`, and re-running detection
confirms the hash duplicates were **already merged in a prior session**.

646 rows are marked `merged`. Every one of them **shares its file path with
its surviving twin** — they are re-imports that catalogued the same physical
file twice, so the merge correctly deleted the row and left the file. There is
no redundant audio to reclaim; deleting those files would destroy the
originals. `ContentGate` already hides merged rows from browsing and counts.

So "verify then delete the redundant files" resolves to: verified, and there
is nothing to delete.

### The metadata premise was stale

S-95 said every music title was a filename and nothing had matched. Measured
now:

- **100%** of music rows have an artist.
- **94%** have an album.
- **5** titles out of 8,313 resemble a filename.

File-tag (getID3) enrichment already populated clean metadata — Pink Floyd /
The Wall, Radiohead, Incubus / Make Yourself. `match_confidence = none` means
no *external* provider re-confirmed it, not that the data is missing.

A full external re-enrichment is therefore **held**: running it now would risk
overwriting good tag data with weaker provider guesses, and it moves files as
it re-files them. That is a different action from what "the titles are
filenames, fix them" implied, so it waits on a decision made with the real
numbers.

### A re-enrichment command, for when it is wanted

`music:reenrich` runs the metadata pipeline over existing music without a
re-import. It writes metadata only — no file moves, unlike the import job — and
snapshots to MetadataHistory, so any change is recoverable and manual fields
are preserved. `--sample=N` shows match quality against a handful of rows
before committing to the whole library; `--only-unmatched` skips rows a
provider already matched. Sampled against 8 real tracks: all matched a
provider, none lost data.

## Worth knowing

- **The database was backed up** before detection ran, and detection without
  `--merge` deletes nothing.
- **No library data changed** in this PR. The sample enrichment run persisted
  nothing that altered a title, and the duplicate pass found nothing to merge.
- The 646 merged rows are correct as they stand; they are not shown to anyone
  and hold no extra files.

## Still wrong / outstanding

- **External enrichment is unrun, by design**, pending the owner's decision now
  that the data is known to be good rather than absent.
- **Two files remain genuinely lost** (ids 3410, 3887 from S-137) — no copy on
  disk under any path.
- No keyed metadata providers are configured (Spotify, AcoustID, Discogs,
  Last.fm). MusicBrainz and iTunes need no key and are what a re-enrichment
  would match against.

## Tests

**PHP 568** — unchanged; this PR adds a command and an investigation, not new
behaviour to gate. The command was exercised against the live library on a
sample.
