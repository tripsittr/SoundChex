# 263 — Stop rewriting every track to embed a cover it already has

**Merged** 2026-10-05 · **Issues** #463

Re-enriching the library rewrote every audio file, to embed artwork that was
already in them.

## What changed

### An "already embedded" check

`CoverEmbedder::embed()` had none. It runs on every re-enrichment of an `Exact`
music match, and embedding means ffmpeg remuxes the file to a temporary and
renames it over the original. So a library-wide re-enrichment rewrote every
track, changed every mtime, and invalidated every stored hash — to write
artwork byte-for-byte identical to what was there.

It now extracts the attached picture and compares its bytes with the cover
file's. Bytes, not presence: a file can carry a *different* cover, which does
need replacing.

Any failure answers "not embedded". Re-embedding a cover that was already there
costs one remux; skipping one that is genuinely missing leaves the library
without artwork, so proceeding is the safer default.

### The stored hash is cleared after a rewrite

The bytes at `file_path` change when a cover is embedded, so a hash describing
the old bytes must not survive (rule 2). Left in place, this is the condition
where two rows on one file held two different hashes with neither matching the
file, and a reviewed track kept returning to the review queue (S-346).

## Worth knowing

- No migration.
- **The first re-enrichment after this still rewrites each file once**, because
  nothing recorded whether a cover was embedded before now — the check reads the
  file itself, so the first pass has to look. Subsequent runs skip.
- The two new tests generate a real MP3 and JPEG with ffmpeg. The existing tests
  in this file use placeholder bytes that ffmpeg cannot read, so they never
  reached this path — which is why the bug survived a suite that covers the
  class. They skip if ffmpeg is unavailable.
- Verified the detection round-trip against real ffmpeg before writing the
  check: no cover makes `-map 0:v:0` fail (reads as "not embedded"), and an
  embedded cover extracts to bytes that hash-match the source.
