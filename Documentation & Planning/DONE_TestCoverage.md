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

Complete. Three layers run green:

| Layer | Command | Count |
| --- | --- | --- |
| PHP | `php artisan test` | 124 |
| Vitest | `npm test` | 25 |
| Playwright | `npm run test:e2e` | 17 |

**Covered:** `LibraryOrganizer`, `DuplicateDetector`, `ContentGate` and profile
permissions, `SearchService`, `LibraryScanner`, `LibraryCsv`, `EpisodeParser`,
`MediaTranscoder`, `OcrService`, reader reflow, byte formatting, caption
preferences, playback across navigation, admin access by direct URL, reader
annotations across a reload, and offline playback with the network cut.

**Every guard was verified by sabotage** — break it, confirm a test goes red,
restore. Done for the confidence gate, the delete re-verification, the rating
cap, profile permissions, the dialogue gate, SPA navigation, the settle window,
extension stripping, the OCR language validation, CSV re-import dedup, the
dashboard gate, annotation persistence and offline playback.

### Five bugs found by writing the tests

Each was silent, and each is fixed and covered:

1. **`canAccessPanel()` gated on `hasAnyRole()`** — a profile holding a granted
   permission was refused at the panel door, making per-profile permissions
   unusable for their own purpose.
2. **Music still stopped on every navigation.** Livewire only binds click
   interception when a Livewire component is present, and the media center is
   plain Blade. See `PersistentPlayback.md`.
3. **Episode codes were stripped as file extensions.** The scanner passes a
   basename, and `pathinfo()` then ate `.S01E01` off a dotted release name, so
   those files were catalogued as films.
4. **The admin dashboard was ungated.** A profile capped at PG could open
   `/admin` and read library counts, storage totals and a Recently Added table
   listing titles above its rating.
5. **A missing IndexedDB record read as a hit.** `result?.result ?? result`
   fell through to the IDBRequest object, so `localUrl()` threw instead of
   returning null for anything not downloaded.

### Deliberately not covered

- `MetadataHistory` and `WatchProviders` — thin wrappers over data the
  metadata sources own; there is no refusal or destructive path in either.
- The PDF rect-merging helper is module-private and needs a real canvas and
  viewport. Reshaping it to fit a unit test would change the code to suit the
  test.
- Driving text selection in epub.js. That tests the browser's selection API,
  not this project.

### Isolation

Browser tests run against their own app: separate SQLite file, separate storage
root (`LOCAL_DISK_ROOT`), media generated by ffmpeg. `tests/e2e/bootstrap.sh`
deletes and rebuilds both, so it refuses to run unless each is a scratch path.
PHP tests use `:memory:`, `Storage::fake()` and `Http::preventStrayRequests()`.
Nothing in any layer can reach the real library.
