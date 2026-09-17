# 077 — Stale issue cleanup + dead code removal

**Merged** 2026-09-17 · **Issues** S-38, S-95, S-108, S-109, S-111, S-112, S-113, S-117

Housekeeping: six issues that were "fixed in this branch" but never moved out of
Open, one whose premise went stale, and one genuine dead-code removal.

## What changed

### Removed dead code (S-38)

`useLocalSource()` in `resources/js/download-button.js` was exported but
referenced nowhere — the player, reader and watch surfaces use `localUrl()`
directly. Removed it before someone wired up a second, divergent path. `localUrl`
itself stays (still imported and re-exported on `window.soundchexDownloads`).

### Moved to Done (already fixed, present on main)

- **S-117** — listening time (`listened_seconds`, seek guard, per-profile stamp).
- **S-113** — "download all" row states + repaint/by-reference fixes.
- **S-112** — repo-move breakage (plists, cargo target, `storage:link`).
- **S-111** — space gate fails closed; `confirmSpace()`; `tight` state.
- **S-109** — shell redirect test now has teeth.
- **S-108** — `bootstrap.sh` clears `-wal`/`-shm`.

Each was verified present in the current tree before moving.

### Resolved on a stale premise (S-95)

The duplicate-deletion half was already done (646 merged, nothing redundant to
remove). The metadata half is measurably stale: 100% of rows have an artist,
94% an album, only 5 titles resemble a filename — getID3 tags already populated
clean metadata. `match_confidence = none` means no external provider re-confirmed
it, not that titles are filenames. No safe automated title fix exists without net
harm; external re-enrichment is held pending the owner. Moved to Done.

## Tests

Vitest 98/98, JS parses, `npm run build` clean (service worker re-stamped). No
behaviour change beyond removing an unused function.
