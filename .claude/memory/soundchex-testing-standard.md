---
name: soundchex-testing-standard
description: "Blaze wants tests written for everything in SoundChex, across three layers — PHP, Vitest, Playwright — with specific rules about what each must cover"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: b8c115fe-eb5d-4851-b41f-6a5259bf8b30
  modified: 2026-08-13T19:39:47.892Z
---

Write tests for everything built in SoundChex, alongside the work rather than
as a final pass. Asked for this after learning the suite was PHP-only (24
tests, 15 of them one parser) against ~4,580 lines of untested JavaScript and
~18,900 lines of PHP.

**Why:** every bug found in this project so far was caught by hand — a stuck
dialog, hyphen rejoining producing "port hole", a stale `MediaBrowser` scoping
to the previous profile, double-bound download buttons, `@livewireScriptConfig`
silently doing nothing. Most were frontend or integration, so unit tests alone
would have caught few of them. Blaze specifically asked for the kinds of tests
to be spelled out in detail, not left as "add tests".

**How to apply:**

Three layers, chosen by what the code does:

1. **PHP (`php artisan test`)** — required for anything that moves or deletes a
   file (`LibraryOrganizer`, `DuplicateDetector`), anything deciding access
   (`ContentGate`, profile permissions, panel gates), and every parser or
   converter. Test the **refusals** as hard as the successes: that a
   wrong-confidence match does not move a file, that a diverged file is not
   deleted, that an existing file is never overwritten.
2. **Vitest (`npm run test`)** — pure JS logic with no DOM: paragraph reflow,
   rectangle merging, timestamp conversion, byte formatting, quota arithmetic.
3. **Playwright (`npm run test:e2e`)** — integration behaviour: audio surviving
   a navigation, highlights surviving a reload, a downloaded file playing
   offline, a member profile refused in the admin panel.

Two rules that are non-negotiable:

- **Access must be tested by direct URL**, never by whether a link renders.
  Filament and Laravel routes resolve whether or not anything links to them, so
  a navigation-only test proves nothing.
- **Tests must never touch the real library.** In-memory database,
  `Storage::fake()`, `Http::fake()`. A test that moves a real file can destroy
  the thing it was written to protect.

The validation step: **break a guard on purpose and confirm a test fails.** If
the suite still passes with the confidence check or byte re-verification
removed, the tests assert that code ran, not that it protected anything.

Full detail lives in `AGENTS.md` under "Testing"; the rollout plan is
`Documentation & Planning/TestCoverage.md`. See also
[[soundchex-plan-workflow]].
