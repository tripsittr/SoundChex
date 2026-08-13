# Test Coverage

Put a safety net under the parts that can lose data or leak access.

## Where things stand

24 PHP tests, 15 of which cover the episode parser written yesterday. The rest
is auth and routing. There are **no frontend tests at all** — no Vitest, no
Playwright, not even a `test` script in `package.json`.

| | Lines | Tested |
|---|---|---|
| PHP (`app/`) | ~18,900 | auth, routing, episode parsing |
| JavaScript (`resources/js/`) | ~4,580 | nothing |

Untested and consequential:

- `LibraryOrganizer` — **moves the user's files**
- `DuplicateDetector` — **deletes them**
- `ContentGate` and profile permissions — decide who sees and does what
- The whole reader, player, downloads and navigation layer

## Why it matters here

Every bug found in recent sessions was caught by hand, not by a test: the
stuck dialog, hyphen rejoining producing "port hole", a stale `MediaBrowser`
scoping to the previous profile, double-bound download buttons,
`@livewireScriptConfig` silently doing nothing, `LibrarySettingsPage` resolving
to a permission that does not exist.

Most were frontend or integration. Unit tests alone would have caught few of
them, which is why this covers all three layers rather than the cheapest one.

## Order

**PHP first.** It guards the file-destroying paths and the permission gates,
and it needs no new tooling. Each layer is useful on its own, so this can stop
after any of them.

### 1. PHP — destructive paths and permissions

- `LibraryOrganizer`: correct path per type; refuses without an exact match;
  refuses without the required field (year, author, episode numbers); never
  overwrites a different file; recognises an identical one.
- `DuplicateDetector`: re-verifies bytes immediately before deleting; refuses
  when contents diverged; handles two rows sharing one file without deleting
  it.
- `ContentGate`: a capped profile is refused in browse, search, and by direct
  URL on detail, stream and watch.
- Profile permissions: a member has nothing until granted; the owner cannot be
  locked out; a granted permission grants exactly itself.
- `SubtitleConverter`, `BookSearch`, `OcrService` parsing — pure functions with
  known-tricky inputs.

### 2. Vitest — pure JavaScript logic

The functions with real algorithms and no DOM:

- `linesToParagraphs` — hyphen rejoining, paragraph breaks
- rect merging for highlights
- `timestampToSeconds` and cue parsing
- `formatBytes`, quota arithmetic

### 3. Playwright — the integration bugs

What actually broke, in a real browser:

- Play a track, navigate, audio still playing
- Open the reader, highlight, reload, highlight still there
- Download, go offline, play from the device
- A member profile is refused in the admin panel

## Constraints

- **Tests must not touch the real library.** They run against an in-memory
  SQLite database with fixtures in a temp directory. A test that moves a real
  file would be worse than no test.
- Playwright needs the app served; it uses the existing `artisan serve` rather
  than standing up its own stack.
- No test may depend on network access. TMDB and OpenSubtitles are faked.

## Verification

- `php artisan test` green, and meaningfully larger than 24.
- `npm run test` green.
- `npm run test:e2e` green against a running server.
- Deliberately breaking a guard — removing the confidence check, say — makes a
  test fail. A suite that passes with the safety removed is not a safety net.

## Status

Not started.
