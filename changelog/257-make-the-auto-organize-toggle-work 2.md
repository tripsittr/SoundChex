# 257 — Make the auto-organize toggle work

**Merged** 2026-10-05 · **Issues** #456

Turning auto-organize off in the admin panel did not stop files being moved.

## What changed

### The setting is read from the toggle, not only from config

`EnrichMediaItemJob::fileIntoLibrary()` guarded filing with
`config('library.auto_organize', true)`, while the Library settings page writes
the toggle to the database as `library_auto_organize`. The two never met:
`LibrarySettings::autoOrganize()` existed and had **no callers at all**, so the
switch in the UI changed nothing and files moved on every enrichment.

The job now calls `LibrarySettings::autoOrganize()`, which reads the stored
toggle and falls back to the config default when nothing is stored. A stored
`false` wins over a config `true`, which is already the documented contract of
`LibrarySettings::value()` — "an admin who switched a toggle off means off".

This mattered more than a stale default normally would: with #454 (a case-only
rename could delete the only copy) and #455 (every title search scored
`Exact`, the one confidence allowed to move a video) both live, this toggle was
the control a user would reach for to stop files moving, and it was inert.

Every other `LibrarySettings` accessor was checked for the same orphaning; this
was the only one.

## Worth knowing

- No migration. A library with nothing stored behaves exactly as before.
- `LIBRARY_AUTO_ORGANIZE=false` in `.env` still works and is still the setting
  on the Mac. With this change the admin toggle is an equivalent control, and
  the env var remains the fallback default.
- The three tests that already passed before the fix are the ones that matter
  for not over-correcting: the toggle on still files, and the env default still
  applies when nothing is stored.
