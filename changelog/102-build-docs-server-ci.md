# 102 — Build docs reflect the server-runtime CI (S-14/S-54)

*2026-09-18.*

## Done

`docs/BuildingOnEachPlatform.md` was stale after S-151: it still said "no CI
configured yet" and marked Windows/Linux as "never built". Updated to reflect
reality, without overclaiming:

- Split the status into **client/desktop app** vs **bundled server runtime** —
  two different things that build at different stages.
- The **server runtime** now builds in CI for macOS (arm64/x86_64), Linux
  (x86_64/aarch64) and Windows (x86_64 CLI) via `build-server.yml`.
- The **client/desktop app** is still only built by hand on macOS/iOS — kept
  honest, since that has not changed.
- Fixed the "there is no CI configured yet" line.

## Notes

Relates to S-14 (Windows/Linux builds untested) and S-54 (buildable on all
platforms): the *server* half is substantially addressed; the *client app* half
on Windows/Linux/Android is still open. Docs only.
