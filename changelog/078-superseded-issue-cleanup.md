# 078 — Resolve/close superseded issues

**Merged** 2026-09-17 · **Issues** S-06, S-07, S-45

More housekeeping over old open issues — one verified fixed, two superseded by
the native rebuild.

## What changed

- **S-45 — orphaned re-filed rows.** Verified against the live database: rows
  622, 623 and 1107 are gone; the correctly-filed copies (3484, 3499, 2984)
  remain. Cleaned up during the earlier dedup/path work (S-95 / S-137). Moved to
  Done.
- **S-06 — IndexedDB ~1 GB download cap.** Formally superseded by S-107 and, in
  practice, resolved by the native iOS app, which writes downloads to real disk
  past the cap. Android (A-05) will do the same. Moved to Done (superseded).
- **S-07 — background downloads/audio stop when backgrounded.** Absorbed by
  S-107 and resolved natively: the iOS app has background audio and background
  `URLSession` downloads. Android to follow (A-04/A-05). Moved to Done
  (superseded).

Docs-only. No code change.
