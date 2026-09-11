# 037 — A header, and a hundred queries

**Merged** 2026-09-11 · **Issues** S-129, S-130, S-131, S-132, S-133

A security and performance review of the whole application. One real
vulnerability, in the header that names a file when you stream it. And the home
page, which was building eight rails for every media type and using one of
them.

## What changed

### `Content-Disposition` no longer trusts the title

Both streaming endpoints built the header by hand:

```php
'Content-Disposition' => 'inline; filename="' . addslashes($item->title) . '"',
```

`addslashes()` escapes quotes. It leaves CR and LF alone, and Symfony stores a
header value exactly as given — so a title containing `\r\n` ends the header
and starts another one.

That matters because titles are not ours. They come from tags inside files the
user imported and from TMDB, MusicBrainz and Open Library, and everything else
in the codebase treats them as trusted text.

Now handed to `ResponseHeaderBag::makeDisposition()`, which refuses control
characters outright. It also fixes something the hand-rolled version got quietly
wrong: RFC 6266 `filename*` encoding, so a Cyrillic or accented title downloads
with its own name instead of a mangled one.

### The home page stopped throwing away most of its work

`home()` wanted one rail per type — "Recently Added". It asked `rowsForType()`,
which builds the *entire* browse page for a type: four fixed rails plus up to
four genre rails, eight queries each carrying a full eager-load batch. Then it
kept the first and discarded the rest, once per media type.

It also grew. The genre rails come from how many distinct genres the library
holds, so the discarded work scaled with the library — 58 queries at four items,
69 after adding forty more.

Two smaller things behind the same page:

- **Every query loaded all four metadata tables.** A music query asked
  `movie_metadata`, `show_metadata` and `book_metadata` for rows it already knew
  were not there — three round-trips per batch that always returned nothing.
- **`hero()` ran its query twice**, once filtered to items with artwork and
  again unfiltered as a fallback. When artwork exists — the normal case — the
  second one ran a full eager-load batch and was thrown away.

Against the real library:

| Page | Before | After |
| --- | --- | --- |
| `/app` | 140 queries | **23** |
| `/app/music` | 57 queries | **36** |

`/app/music` was not touched; it improved because it goes through the same
`base()`.

### A finished download stopped spinning forever

Found by the test suite rather than by reading: a batch download completed, the
file was on the device, and the row's icon kept spinning until you navigated
away and came back.

Two guards, each correct on its own, and each added for a real reason:

- `runBatch()` marks every row as downloading before it starts, so a long batch
  does not leave a list looking like nothing is happening.
- The repainter skips any button already showing `downloading`, because a
  repaint landing mid-transfer otherwise resets a live download to idle — which
  looks exactly like a tap that did nothing.

Together they deadlock. Every row in the batch says `downloading`, so the
repaint that is meant to write the finished state skips exactly those rows.

What made it slow to find is that **nothing was broken**. The queue reported
started, finished, idle. The fetch returned 200 with all 119 KB. Storage had
2.1 GB free against 835 KB used. Everything succeeded and only the icon
disagreed — the bug was in who was permitted to *write* the state.

Now each row is marked from its own result as it settles. The failure path
already did this, for the same underlying reason: a failed track is absent from
IndexedDB, so a repaint reads it as idle. The success path had been leaning on
the repaint.

### A test that was failing for doing its job

`downloads-batch.spec.js` — "a queue survives the app closing" — failed
intermittently on the mobile project. The queue was fine. `resumeDownloads()`
runs on page load and immediately picks up everything it finds in storage, and
the fixture files are a few hundred bytes, so by the time the test looked, the
queue had correctly drained itself.

It now reads what was written down *before* the reload, then polls for the
drain — which asserts the more useful half anyway: the app picks the work back
up. 6 runs in 6, from 1 in 5.

### `instr()` hardened

`TopItemsTable` forces row order with a raw `instr()` fragment built by
interpolating ids. Those ids come from the database, not a request, so this was
not exploitable. Ids are now cast to `int` anyway — an interpolated list in a
raw fragment becomes an injection the moment its source changes, and the cast
costs nothing.

## Worth knowing

- **No migrations.** Nothing in this branch touches the schema.
- **The findings document is the real output**, at
  `Documentation & Planning/Security/findings/2026-09-11-security-and-performance-review.md`.
  It records what was audited *clean* as well as what was fixed — route auth,
  API throttling, updater path traversal, the transfer token leak, SQL
  injection, mass assignment, search XSS — with the evidence for each, because
  otherwise the next reviewer cannot tell a checked area from an unchecked one.
- **`policy.blade.php` and `terms.blade.php` use `{!! !!}` and were left alone.**
  Both are dead — no route, no include reaches either. That is the finding: if
  either is ever wired up, the escaping has to be revisited first.
- **Nothing in the app looks different.** All of this is behaviour-preserving
  except the header, where a mangled non-ASCII filename now comes through
  correctly.

## Still wrong

- `/app/music` at 36 queries is bounded and does not scale with row count, but
  it is not obviously *minimal*. It is no longer the page anyone notices, so it
  was left.
- **Two tests were written wrong here, and both were green.** The hero artwork
  test searched the whole page — but both films also render in the rail below
  the hero, so it passed with the fix reverted. Scoped to the hero section, it
  *still* passed, because both rows were created in the same second and
  `latest()` was a tie SQLite happened to break the right way. Fixed by setting
  the timestamps explicitly. Written down because neither failure is visible at
  review: the test is green, the assertion reads sensibly, and it guards
  nothing.
- The performance numbers are from the real library on this machine. Unverified
  on the phone, where the win should be larger and not smaller.
- The spinner bug (S-132) was **pre-existing on this branch**, not introduced
  here — verified by reverting this branch's changes and watching both tests
  fail identically. It came from the earlier downloads work and was fixed here
  because the review surfaced it.
- **I got the flaky test wrong first.** Reverting the spinner fix made it pass,
  which looked like proof the fix caused it. It was not: the original code fails
  4 runs in 5, and one run per side is not a bisect when the test is
  non-deterministic. The first hypothesis about *why* it flaked — a race against
  the 4-second runner timers — was also wrong, and was only ruled out by logging
  what the stored queue actually held.

## Tests

**PHP: 526 passed**, 1,148 assertions. **Vitest: 98 passed.**
**Playwright: 306 passed, 0 failed** across every project — desktop, mobile,
shell and uploads. Desktop alone was 190 before the spinner fix, with the two
failures it addresses.

New: `HomePageCostTest` (6) — a query-count bound, a proof the count does not
grow with the library, a per-table eager-load assertion, and three rendering
tests. The rendering half matters more than the counting half: a narrowed
eager-load fails *silently*, leaving the page intact with a blank subtitle, so
the test asserts that all four rails render with subtitles drawn from all four
metadata tables.

Also `HeaderInjectionTest` (3), from the security fix.

Every fix in this branch has a test verified to fail when the fix is reverted.
