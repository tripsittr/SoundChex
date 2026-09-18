# 100 — Preflight for the stale-cargo build trap (S-127)

*2026-09-18.*

## Done

When the repo moves, `src-tauri/target` keeps the OLD absolute build path baked
in, and each cargo target only breaks when it is next built — surfacing as a
**misleading** Tauri error that names a permissions file
(`… app_hide.toml: No such file`), not a stale cache. Three separate builds
(iOS, `cargo check`, `build:server`) each lost time to this, read as a plugin
bug (S-127).

- **`scripts/check-stale-target.mjs`** — a preflight that scans cargo's recorded
  paths in `src-tauri/target` and, if any point at a directory that no longer
  exists, fails with a clear message naming the exact
  `rm -rf src-tauri/target/<target>` to run.
- Wired into `tauri:build`, `build:server` and `dev:server` (via
  `npm run check:target`), so the trap is caught before the build, with the
  right error instead of someone else's.

## Verified

- Teeth: a fabricated stale fingerprint (path to a missing dir) → exit 1 with the
  target named. The current, healthy target → exit 0.
- Documented in `docs/WorkingOnSoundChex.md` and README.
