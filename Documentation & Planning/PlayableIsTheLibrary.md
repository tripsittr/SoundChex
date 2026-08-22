# The playable file is the library item

Make the browser-playable version a real filed item, and move the original out
of the way into an archive.

## Why

Backrooms stopped playing, and the reason says everything about the current
design. The converted file was sitting on disk the whole time — 4.7 GB of it —
but the only thing that knew it existed was a `converted_path` column. When the
catalogue was rebuilt, `library:recover` walked the filed library, found the
`.mkv`, and produced a row pointing at a file no browser can decode. The
conversion was orphaned in `media/converted/` where nothing looks.

The current arrangement:

- **`media/library/`** — filed originals, the tree scans walk
- **`media/converted/`** — browser-playable copies, excluded from scans,
  reachable only through a database column

So the file people actually watch is invisible to every mechanism that keeps the
library honest. It cannot be found by a scan, cannot be recovered, and is
identified by nothing but a foreign key.

## What changes

**The playable file becomes the item.** A conversion is filed by the same
organiser as everything else — `Movies/Backrooms (2026)/Backrooms (2026).mp4` —
and `file_path` points at it. It appears in scans, survives a rebuild, and needs
no special case.

**The original moves to `media/archive/`.** Out of the library tree, excluded
from scans, kept whole. Referenced by a new `archived_path` column so the
download-the-original feature keeps working.

**Nothing is compressed.** The measurement that settled it: this film's MKV is
1.8 GB of HEVC and its MP4 is 4.7 GB of H.264. The source is already the smaller
file — re-encoding for browser compatibility made it 2.6× larger. Video is
compressed already; gzip on a 1.8 GB HEVC saves almost nothing and costs the
ability to play it without unpacking first.

**Nothing is deleted.** An archive that deletes on a conversion the user has not
yet watched is one bad transcode away from losing a film.

## Plan

### 1. An archive location (~half a day)

`media/archive/`, added to `scan_exclude` so the scanner does not re-import what
it just moved aside. An `archived_path` column beside `file_path`, and
`MediaItem::originalPath()` preferring it so downloads still offer the source.

### 2. File the conversion (~1 day)

The transcode job hands its output to `LibraryOrganizer` rather than writing to
`media/converted/`. On success: move the original to the archive, point
`file_path` at the filed conversion, and set `archived_path`.

Ordered so a crash cannot lose anything. The conversion is filed first, verified
present, and only then is the original moved — a failure at any point leaves a
playable file and an original in one place or the other, never neither.

### 3. Migrate what exists (~half a day)

One command for the existing arrangement: for every row with a `converted_path`,
file the conversion, archive the original, and clear the column. Idempotent, and
`--dry-run` first, because it moves real media.

### 4. Teach recovery about archives (~half a day)

`library:recover` should skip `media/archive/` — otherwise a rebuild re-imports
every original as a duplicate of the film beside it. This is the specific gap
that produced the bug, and it stays a gap until recovery knows the folder exists.

### 5. Tests (~1 day)

- A conversion is filed where the organiser would put it, and `file_path`
  follows it.
- The original ends up in the archive, and `originalPath()` still finds it.
- A scan does not re-import an archived original.
- `library:recover` ignores the archive.
- A transcode that fails leaves the original where it was.
- The migration is idempotent — running it twice moves nothing twice.

**Total: ~3–4 days.**

## Deliberately not doing

- **Compressing originals.** Measured and rejected above.
- **Deleting originals.** The archive exists so a bad conversion is recoverable.
  Deleting can be a separate, explicit choice later.
- **Re-transcoding anything.** Existing conversions are fine; they are in the
  wrong place, which is a move rather than an encode.

## Risks

- **It moves real media.** Every step needs a dry run and a verification before
  the move, not after.
- **Disk space during migration.** Filing a conversion before archiving the
  original means both exist momentarily. On this library that is 6.5 GB for one
  film; a check before starting is cheaper than a full disk halfway through.
- **A half-migrated library.** Ordering makes a crash safe rather than
  impossible: something playable always exists, and the original is always
  somewhere.

## Not started

---

# Part two: a library the user controls

Raised alongside the archive work: move storage out of the app, let the user
choose sorting patterns and naming conventions, and re-file when things change.

## Where storage lives

**Already movable, and not exposed.** `LOCAL_DISK_ROOT` in `.env` relocates the
entire media tree — an external drive, a NAS mount, anywhere the process can
write. Nothing in the code assumes it is inside the application.

What is missing is the interface, and one hard part behind it: **changing the
root does not move what is already there.** A settings field that silently
repoints at an empty directory would look like the library had been deleted.

So the setting has to either move the existing tree — thousands of files, tens
of gigabytes, resumable and verifiable — or refuse to change while the library
is populated and say why. The field is an afternoon; the move is the project.

## Sorting and naming

`LibraryOrganizer::segmentsFor()` already builds a path per type. Making that a
template is a contained change:

    Music   {artist}/{album}/{track} {title}
    Movies  {title} ({year})/{title} ({year})
    Shows   {series}/Season {season}/{series} S{season}E{episode}
    Books   {author}/{title}

with a preview against real items before anything is saved — a pattern that
produces `Unknown Artist/Unknown Album/` for half a library should be visible
before it is applied, not after.

## Re-filing when things change

**This is the part worth being careful about.** Re-filing on every save means a
metadata correction moves files on disk. A wrong pattern reorganises the whole
library; correcting the pattern reorganises it again.

What that needs to be safe:

- **A preview** showing what moves where, before anything moves.
- **A dry run** as the default, with `--apply` the deliberate act.
- **Batched and resumable**, because two thousand files is not one transaction.
- **A record of what moved**, so a bad run can be undone rather than
  reconstructed by hand.
- **Nothing on save.** Re-filing is an explicit operation, not a side effect of
  fixing a typo in an album name.

The last is the one I would hold to. Everything else here is a preference; that
one is what stops a settings page from being able to lose a library.

## Suggested order

1. **The archive work above** — it is the bug that prompted all of this.
2. **Naming templates**, with a preview and a dry run. No automatic re-filing.
3. **A re-file command**, explicit, resumable, reversible.
4. **Storage location in settings**, once moving an existing library is solved.

**Total: ~1 week beyond the archive work**, most of it in making moves safe
rather than in the settings themselves.
