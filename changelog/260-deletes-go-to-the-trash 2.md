# 260 — Deletes go to the trash

**Merged** 2026-10-05 · **Issues** #464

Nothing in the library is unlinked any more. A file the app deletes is moved
to `media/.trash/<date>/` and kept for 30 days, so a wrong delete is a support
question rather than a loss.

## What changed

### A new `MediaTrash`, and the two real delete paths route through it

`DuplicateDetector::deleteFile()` (a resolved duplicate) and
`LibraryOrganizer::adoptExisting()` (a copy already filed at the target) were
the only two places the app removes a user's media. Both now call
`MediaTrash::discard()`.

The reason is the bug this phase opened with: a path-comparison mistake in the
organizer deleted the only copy of a file (#454), and there was nothing to
restore from. Verifying before destroying is already the rule (AGENTS.md rule
2) — but a verification can be wrong, and a delete that is really a move makes
the next mistake of its kind survivable.

Everything else that calls `unlink()` — temporary files, HLS segments, OCR
scratch, old backups, orphaned artwork — keeps doing so. Those are not the
user's media and the trash would only fill with them.

### The trash keeps enough path to be readable

A file is trashed under its original relative location, not its basename:
`media/.trash/2026-10-05/media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3`.
`03 Chicago.mp3` exists on many albums, and trashing by name alone would suffix
the second one into something nobody could identify later.

It lives on the same disk as the library deliberately. A move within one
filesystem is atomic and instant whatever the size; a copy to another volume
could half-finish on a full disk and is slow for a 40 GB remux.

### Restore refuses rather than overwrites

`MediaTrash::restore()` will not write over a file already at the target. That
file may be the copy that was *kept* when this one was trashed, so overwriting
it would destroy the survivor — the same shape of mistake as #454.

### A scheduled purge, and the read-only case

`library:purge-trash` runs daily at 04:30, after the backup so the two never
compete for disk. It takes `--days` and `--dry-run`. Retention is
`library.trash_days` (30 by default); **zero disables the purge entirely**,
which is the "keep until emptied by hand" setting, and the command then says so
rather than ignoring it.

The read-only handling that lived in `deleteFile()` moved into `MediaTrash`,
because a move is refused for the same reason an unlink was: `unlink()` will not
remove a read-only file on Windows, and 285 of 2,843 files in one real library
carry that attribute. Every merge of one of those used to fail silently and the
duplicate came back at the next sweep.

## Worth knowing

- **New config:** `TRASH_ROOT` (default `media/.trash`) and `TRASH_DAYS`
  (default 30). No migration.
- Disk usage grows by whatever is deleted, for up to 30 days. On a library
  where duplicates are being resolved in bulk that is real space — worth a
  `library:purge-trash --days=7` if it matters more than the undo window.
- `media/.trash` is added to `library.scan_exclude`. The exclusion list is
  explicit paths, not a dot-prefix rule, so without that entry the scanner
  would walk the trash and re-import every file in it — a resolved duplicate
  would come back on the next pass and the thirty-day undo window would become
  thirty days of re-importing the same file. A test asserts the configured
  trash root is excluded, so changing `TRASH_ROOT` without excluding it fails.
- Existing duplicate tests assert the file is gone from its old path, which
  trashing satisfies, so all 93 still pass unchanged.
