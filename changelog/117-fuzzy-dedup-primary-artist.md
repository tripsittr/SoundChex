# 117 — Fuzzy duplicate matching uses the primary artist

*2026-09-19.* · **Issue** S-271

## Done

The fuzzy (tag-based) duplicate pass matched on the raw `artist` credit, so the
same song tagged two different ways — "$uicideboy$" on one copy and
"$uicideboy$, Pouya" on another — never paired up. It now matches on the
**primary artist** (`COALESCE(primary_artist, artist)`), so a featured variant of
a track finds its lead-artist twin. Album and length guards are unchanged, so a
single and its album cut, or a radio edit and the LP version, stay separate.

## Worth knowing

- **Re-scan to apply:** detection runs at catalogue time, so the ~274 groups
  (~290 copies) still unflagged on the dev library need a re-scan to be caught —
  `php artisan library:duplicates` or the scheduled `DetectDuplicatesJob`. Best run
  **after** the in-progress full re-enrich (S-273) finishes, since it is still
  correcting titles and filling `primary_artist`, which this match keys on.
- Rows without a `primary_artist` fall back to the raw credit, so nothing that
  matched before stops matching.

## Tests

PHP: **681 passing** (+3). New in `ContentDuplicateDetectorTest`: a featured
variant matches the lead artist on the primary; a row with no primary falls back
to the credit; two genuinely different primary artists do not match. Existing
duplicate tests unchanged. Pint clean.
