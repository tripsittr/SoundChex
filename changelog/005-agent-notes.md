# 005 — What one agent learned, for the next one

**Merged** pending

A `.claude/` folder carrying the preferences and hard lessons that were living
in one machine's memory.

## What changed

### `.claude/memory/`

Nine notes, indexed by `MEMORY.md`. Two kinds.

**How this is built** — every feature and bug in `Issues.md` before work
starts, everything to `main` through a pull request with a changelog, tests at
all three layers, logging on anything that can fail.

**What went wrong once** — a `migrate:fresh` that destroyed the real library,
and a hardcoded database path that destroyed it a second time under test.

Three were stale before publishing and were corrected rather than shipped: the
`DONE_` plan convention was replaced by `Issues.md` on 22 August, and two notes
still described it.

### `.claude/scripts/watch-tests.sh`

A live view of a Playwright run — progress, and failures as they happen rather
than at the end. Generalised from a throwaway: it took its log path from a
hardcoded `/tmp/pw3.txt` and announced itself as watching one particular PR.

### `.claude/README.md`

Points at `AGENTS.md` first, then the four notes worth reading before touching
anything, then three things that will otherwise cost a day: exit codes that lie,
tests that pass with the fix removed, and "fixed" claims made without a scope.

## Worth knowing

- **`.gitignore` was excluding it**, the same `*.MD` rule that had been quietly
  excluding `docs/` and `changelog/`. Third time — worth checking whenever a
  new folder of markdown appears.
- Checked before publishing: no email, no API key, no tailnet or LAN addresses,
  no reference to the owner by name.
- **App images were already tracked** — logo, icon, favicon, all 54 Tauri
  icons. Nothing to publish there; the check was worth running anyway.

## Tests

**312 PHP · 87 Vitest.** Playwright not run — documentation only.
