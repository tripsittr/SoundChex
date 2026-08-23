# 026 — Files that can actually be placed

**Merged** 2026-08-23 · **Issues** S-106, S-107, GitHub #34

Two faults found by a real 46.3 GB transfer, both costing individual files
rather than stopping the run — and both permanent for the files they hit.

## What changed

### The download handle is released before the file is moved

`fetch()` streams into a `.part` file with `sink()`, then moved it into place
with that handle still open. Windows refuses to move or reopen a file another
handle holds, so the download completed — every byte arrived — and the
placement failed with `Permission denied`.

The name was then poisoned. The `.part` stayed behind, and because a retry
resumes from `filesize($temporary)`, the next attempt asked for a range
starting past the end of the file and was refused. **That file could never
arrive.** Five had failed and thirteen `.part` files were on disk when this
was found, the count growing as the run went on.

`importDatabase()` already released the handle for the catalogue archive.
`fetch()` is the same bug one layer down.

### A `.part` that is already complete is placed rather than re-fetched

The fix above stops new ones. This recovers the ones already stranded: if the
`.part` matches the expected size, it is verified and placed instead of
re-requested.

### Windows can open paths longer than 260 characters

37 files in this library are longer than `MAX_PATH` — the longest is 382
characters, a track credited to nine artists. They fail on open regardless of
permissions, and it looks like a missing file rather than a name too long to
say.

The `\\?\` prefix lifts that limit without a registry change on the receiving
machine. It is applied only to the filesystem call: `$item->path` and the
catalogue keep the name they always had, so nothing needs reconciling
afterwards. Absolute paths only, and a no-op everywhere but Windows.

## Worth knowing

- No migration.
- Existing stranded `.part` files are recovered on the next attempt of that
  item — nothing needs deleting by hand.
- The first test was written badly and caught: it drove `verifyAndPlace()`
  directly and passed with the fix removed, because that method was never
  broken. It now drives `fetch()` against a fake that answers `416` — what a
  real server returns for a range past the end — so re-fetching fails it.
