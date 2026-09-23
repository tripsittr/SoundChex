# 176 — A moved file no longer keeps its old fingerprint

**Merged** 2026-09-23 · **Issues** S-346

"Love Without End, Amen" kept coming back to the metadata review however many
times it was enriched or marked fine. It was not the review guard: the item was
`complete` and stamped `reviewed_at`. Two rows were describing the same file and
neither could be recognised as the other's copy.

## What changed

### `content_hash` is cleared whenever `file_path` moves

`LibraryOrganizer` (both the file-and-move path and `adoptExisting`) and
`library:reconcile-paths` now clear the stored hash when they repoint a row.

The hash describes the bytes at a path. Moving the row to another path left the
old fingerprint in place, describing a file the row no longer pointed at. On
this library 54% of stored hashes no longer matched their file after the S-328
path repair — including both rows of the George Strait pair, where the two rows
shared one file, held two different hashes, and **neither matched the file on
disk**. Byte-identical detection compares those stored hashes, so it could not
see the rows were the same file, and the unresolved pair kept surfacing.

## Worth knowing

- **4193 rows were rehashed** on the live library; 4104 were already correct
  and 29 point at a file that is gone. Nothing was deleted (8326 items before
  and after).
- After rehashing, the metadata review queue is **empty** and the George Strait
  track no longer appears in it.
- 2123 file paths are claimed by more than one row, but 2070 of those groups
  already show a single visible row — the rest are resolved duplicates, which is
  the intended state, not a leak.

## Still wrong

- Nothing re-hashes lazily when a file changes on disk underneath an unchanged
  path, so a re-tagged file keeps its old fingerprint until something rehashes
  it. `library:duplicates --rehash` remains the manual remedy.
