# 262 — A conversion never overwrites its own original

**Merged** 2026-10-05 · **Issues** #458

Promoting a converted copy could destroy the original it was made from, and
leave the catalogue pointing at nothing.

## What changed

### The conversion's target can be the original's own path

`ConversionFiler::filedPathFor()` is the organizer's target with the
*conversion's* extension. For an original already filed as `.mp4` — HEVC in
MP4, which plays nowhere and is converted to H.264 in the same container —
that is the original's own path.

`move()` then renamed the conversion over it with no existence check, and the
archive step that followed moved what was by then the conversion. The original
was gone, the conversion was in the archive, and `file_path` pointed at
nothing.

Every existing test here used an `.mkv` original, so the collision was never
exercised. Promotion now refuses when the target is the original (compared by
`FileIdentity`, so a case-only or symlinked difference does not slip past),
when anything else occupies the filed path, and when the archive path is taken
— the archive had no collision check at all, so a second original of the same
shape silently replaced the first.

A refusal leaves both files where they are and the conversion in place, so a
later run can retry once the underlying problem is fixed.

### These are errors, not warnings

A blocked target leaves the item holding an unplayable original with nothing
the app can do by itself, which is the same severity as a move that fails
outright. `FileOperationsAreLoggedTest` caught this: it blocks the target with
a directory and asserts an `error` is logged. My first attempt logged
`warning`, the existing test failed, and it was right — the test is unchanged.

### The stored hash is cleared on every path write

Three places wrote `file_path` without clearing `content_hash`, against rule 2.
The hash described the original's bytes while the row then pointed at the
conversion — a fingerprint for a file the row no longer described. That is the
condition that left 54% of hashes mismatched after S-328 and blinded
byte-identical duplicate detection (S-346).

## Worth knowing

- No migration.
- An item that hit the old bug has already lost its original and cannot be
  recovered from here; the file is not in the archive either, because the
  archive received the conversion. `library:reconcile-paths` will find the
  broken row.
- Rows carrying a stale hash from a previous promotion keep it until something
  rewrites their path. `library:reconcile-paths` nulls them.
