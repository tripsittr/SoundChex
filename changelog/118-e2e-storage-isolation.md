# 118 — Clean client storage between e2e tests

*2026-09-19.* · **Issue** S-28

## Done

Several browser specs passed alone but failed in a full run: the suite runs
single-worker against one seeded database and one browser origin, so a test that
left a download in IndexedDB or a flag in `localStorage` bled into whatever ran
next — the documented signature of S-28.

`signIn()` (the shared entry point for nearly every spec) now wipes the origin's
client state first — IndexedDB, `localStorage`, `sessionStorage` — via a new
`resetClientStorage()` helper. Every test that signs in starts from a clean
client slate, without a copy-pasted `beforeEach` in each file.

## Worth knowing

- Verified: the four specs S-28 names (`downloads-batch`, `player-session`,
  `now-playing-sheet`, `phone`) now pass together in one desktop run (23/23),
  where a state leak between them was the suspected cause.
- **Not the whole story:** `phone-dl` (and `phone` on the *mobile* project) still
  fail — but those fail alone on a clean tree too, so they are a separate,
  deeper breakage the tracker already flagged as *not* belonging to S-28, not the
  intermittent flakiness this addresses. Left for its own investigation.
- Proving the flakiness is gone needs repeated full 290-test runs; this ships the
  root-cause fix and the isolated verification.

## Tests

E2e helper only. `node --check` clean; the four target specs pass together on the
desktop project.
