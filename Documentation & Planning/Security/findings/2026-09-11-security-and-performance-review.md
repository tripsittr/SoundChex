# Security and performance review — 11 September 2026

A full pass over the application, on branch `queue-once-per-list`. Everything
below was checked against the real library (8,338 items) or by a test written
to prove the point, not read for plausibility.

One real vulnerability was found and fixed, along with two performance defects.

Two further problems came out of running the test suite rather than reading the
code, and both pre-date this review: a finished download whose icon never
stopped spinning, and a persistence test that failed four runs in five. Both
are fixed here.

The rest of what was examined is recorded as audited-clean, with the evidence,
because "we looked at it" is worth as much next time as "we fixed it" — and
without the evidence it is indistinguishable from not having looked.

**Every fix here has a test that was verified to fail without it.** Where a
test passed with its fix reverted, that is noted too: two did, both were wrong,
and both are described below. A green test that cannot go red is worse than no
test, because it is trusted.

---

## Summary

| | Finding | Severity | State |
| --- | --- | --- | --- |
| F-1 | Header injection via `Content-Disposition` | **High** | Fixed |
| F-2 | The home page discarded seven-eighths of its own work | Medium (perf) | Fixed |
| F-3 | Every query eager-loaded all four metadata tables | Medium (perf) | Fixed |
| F-4 | `instr()` built from interpolated ids | Low (hardening) | Fixed |
| F-5 | A finished batch download never left its spinner | Medium | Fixed |
| F-6 | A persistence test that failed 4 runs in 5 | Medium (test) | Fixed |
| F-7 | `{!! !!}` in two unreferenced views | Informational | Left, documented |

---

## F-1 — Header injection via `Content-Disposition` (High, fixed)

**Where.** `MediaCenterController.php:224` and `ReaderController.php:73`.

**What it was.** Both streaming endpoints built the header by hand:

```php
'Content-Disposition' => 'inline; filename="' . addslashes($item->title) . '"',
```

`addslashes()` escapes quotes, backslashes and NUL. It does **not** touch CR or
LF. Symfony stores a header value verbatim, so a title containing `\r\n`
terminates the header and begins a new one of the attacker's choosing.

**Why it was reachable.** This is the part that makes it real rather than
theoretical. Titles are not written by the server operator — they come from
file tags in media the user imported, and from external metadata providers
(TMDB, MusicBrainz, Open Library). A title is attacker-influenced data that the
codebase otherwise treats as trusted.

**Proof.** Reverting the fix and requesting an item titled
`Innocent\r\nX-Injected: yes` produces:

```
'inline; filename="Innocent\r\nX-Injected: yes"'
```

— the injected header, intact, in the response.

**The fix.** Hand off to the implementation that knows the encoding rules:

```php
'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
    ResponseHeaderBag::DISPOSITION_INLINE, (string) $item->title, 'media',
),
```

`makeDisposition()` rejects control characters outright and handles the
RFC 6266 `filename*` encoding for non-ASCII titles, which the hand-rolled
version also got wrong — a Cyrillic or accented album title was mangled.
The third argument is the ASCII fallback (`'media'`, and `'book'` in the
reader) for clients that cannot read `filename*`.

**Test.** `tests/Feature/HeaderInjectionTest.php` — 3 tests. Teeth verified:
all three go red on revert.

---

## F-2 — The home page discarded seven-eighths of its own work (Medium, fixed)

**Where.** `MediaCenterController::home()`.

**What it was.** The home page wants one rail per media type: "Recently Added".
It got it like this:

```php
$items = $this->browser->rowsForType($type)[0]['items'] ?? collect();
```

`rowsForType()` builds the **entire browse page** for a type — Recently Added,
Your Highest Rated, Wishlist, Needs Review, plus up to four genre rails. Eight
queries, each with a full eager-load batch behind it. The home page then kept
the first and threw the other seven away, once per media type.

**Cost.** This is most of why `/app` ran **140 queries**.

**Worse, it scaled.** The genre rails are derived from how many distinct genres
the library holds, so the discarded work grew with the library. Measured on a
test library: 58 queries at four items, 69 after adding forty more.

**The fix.** A dedicated `MediaBrowser::recentlyAdded()`, which `rowsForType()`
now also calls, so the rail has one definition rather than two that can drift.

---

## F-3 — Every query eager-loaded all four metadata tables (Medium, fixed)

**Where.** `MediaBrowser::base()`.

**What it was.** Every browse query, for every type, ran:

```php
->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'tags', 'plays'])
```

A music query therefore asked `movie_metadata`, `show_metadata` and
`book_metadata` for rows it already knew could not exist — three round-trips
per batch, every batch, always returning nothing.

**The fix.** `metadataRelations()` maps the requested types to their own
relations through an exhaustive `match` on the enum, so a new media type is a
build error here rather than a silently missing subtitle. A combined query
still gets several — the Watch page passes movies and shows together, and
`$types` says so.

**What made this risky, and how it was covered.** A narrowed eager-load fails
*quietly*: the page still renders, the subtitle is just blank. So the test
asserts the rendered output — that all four rails appear, with their items, and
with subtitles drawn from all four different metadata tables ("An Artist",
"A Director", "A Creator", "An Author").

---

## Result of F-2 and F-3

Measured against the real library:

| Page | Before | After |
| --- | --- | --- |
| `/app` | 140 queries | **23** |
| `/app/music` | 57 queries | **36** |

84% fewer queries on the home page. `/app/music` improved without being touched,
because it reaches `base()` too.

**A third fix, found while testing these.** `MediaBrowser::hero()` ran
`base()` twice — once filtered to items with artwork, then again unfiltered as
a fallback. When artwork exists, which is the normal case, the second query ran
its whole eager-load batch and was discarded. Folded into one query ordered by
`cover_image_url is null` first, which returns the same row in both branches.

**Tests.** `tests/Feature/HomePageCostTest.php` — 6 tests: a bound on the query
count, a proof that the count does not grow with the library, a per-table
eager-load assertion, and three rendering tests.

**Two of these tests were wrong when first written, and both looked fine:**

1. *The hero artwork test* asserted the cover path appeared in the page. Both
   films also render in the rail below the hero, so it passed with the fix
   reverted. Rewritten to scope the assertion to the hero `<section>`.
2. *It then still passed* — both rows were created in the same second, so
   `latest()` was a tie that SQLite happened to break the right way. The
   timestamps are now set explicitly.

Recorded because both failures are invisible at review: the test is green, the
assertion looks meaningful, and it is guarding nothing.

---

## F-4 — `instr()` built from interpolated ids (Low, hardening)

**Where.** `TopItemsTable.php`.

Row order is forced with a raw `instr()` fragment built by interpolating a list
of ids. Those ids come from the database rather than from a request, so this is
**not exploitable today**. It was hardened anyway — ids are now cast to `int`
and filtered — because an interpolated list inside a raw SQL fragment is a
shape that becomes an injection the moment its source changes, and the cast
costs nothing.

---

## F-5 — A finished batch download never left its spinner (Medium, fixed)

**Where.** `resources/js/download-button.js`, in `runBatch()`.

**How it was found.** Not by reading the code. The desktop Playwright suite
reported two failures, both in the downloads spec. They were first checked
against the pre-review code and failed identically, so they were *not* caused
by this review's changes — but they were real, and on this branch, so they were
fixed here.

**What it was.** A batch download completed successfully, the file landed in
IndexedDB, and the row's icon sat on its spinner indefinitely. Navigating away
and back cleared it.

The two guards involved are each correct on their own:

1. `runBatch()` marks every row `downloading` before starting, so a long batch
   does not leave a list looking untouched.
2. `paintIconDownloadStates()` **skips any button already reading
   `downloading`** — without that, a repaint landing mid-transfer resets a live
   download's spinner to idle, which is indistinguishable from a tap that did
   nothing.

Together they deadlock. Every row in the batch reads `downloading`, so the
repaint that is supposed to write the finished state skips precisely the rows
whose outcome it was meant to write.

**Why it was not obvious.** The queue was working perfectly. Tracing its events
showed `started → finished → idle`, the fetch returned 200 with the full 119 KB,
and storage quota was 2.1 GB against 835 KB used. Everything reported success;
only the icon disagreed. The failure was in who was allowed to *write* the
state, not in the download.

**The fix.** `runBatch()` already knows each track's outcome — it counts them —
so it now marks each row from its own result as the promise settles, rather than
delegating to a repaint that is designed to skip it. The failure path already
did exactly this, for the same underlying reason (a failed track is absent from
IndexedDB, so the repaint reads it as idle); the success path was relying on the
repaint and had no equivalent.

**Test.** The two existing tests in `phone-dl.spec.js` cover it. Teeth verified:
removing the single added line turns both red again. Full downloads spec 18/18,
full desktop project 192/192.

**One thing this cost, worth recording.** Rebuilding assets with `npx vite build`
during the investigation broke a *different* test: the real build is
`npm run build`, which also runs `scripts/embed-offline-shell.mjs` to stamp the
service worker with the asset digest. Vite alone regenerates the manifest and
leaves `sw.js` advertising the previous build's cache version, which surfaces as
one puzzling `service-worker.spec.js` failure comparing two hex strings, several
steps from the cause. Now noted in `docs/WorkingOnSoundChex.md`.

---

## F-6 — A persistence test that failed 4 runs in 5 (Medium, fixed)

**Where.** `tests/e2e/downloads-batch.spec.js`, "a queue survives the app
closing", on the mobile (real WebKit) project.

**What it was.** The test enqueued three downloads, reloaded the page, and
asserted two of them were still in the stored queue. It failed intermittently.

**The false trail, recorded because it nearly became the conclusion.** Reverting
the F-5 fix made it pass, so it looked caused by that change. It was not — the
test is flaky, and a single passing run is not evidence. Measured properly, the
*original* code failed **4 runs in 5**. A one-shot revert-and-retry is not a
bisect when the thing being bisected is non-deterministic; the only honest
version is to run both sides several times.

**The actual cause, which is not timing.** `resumeDownloads()` runs on page load
and immediately re-enqueues everything it finds in storage. The fixture URLs are
a few hundred bytes, so by the time the test could ask, the queue had
legitimately drained itself — leaving one item, not the two expected.

The queue was never broken. The test was watching resume work correctly and
calling it a regression.

**Ruled out along the way.** The runners were 4-second timers, so the first
hypothesis was a race between the reload and the timer. Replacing them with
promises that never settle did not fix it — the failure stayed, and a direct log
of the stored contents showed a consistent `["803"]` rather than the varying
results a race produces. That consistency is what pointed at resume.

**The fix.** Read `localStorage` *before* the reload, so persistence is asserted
against what was actually written down rather than against what survives the
page's own recovery. Then poll for the queue draining, which asserts the half
that matters: the app picks the work back up.

**Verified.** 6 runs in 6 pass, from 1 in 5. Teeth checked by making `persist()`
write an empty array — the test fails, so it still catches genuinely broken
persistence.

---

## F-7 — `{!! !!}` in two unreferenced views (Informational)

`policy.blade.php` and `terms.blade.php` render unescaped content. Both are
**dead** — no route and no include reaches either. Left in place rather than
edited, because the finding worth recording is that they are unrouted: if
either is ever wired up, the escaping must be revisited first.

---

## Audited clean

Each of these was probed, not merely read.

**Route authorisation.** Every admin and app route was requested
unauthenticated. All returned 302 to sign-in; none leaked a body.

**API authentication and throttling.** All API routes sit behind
`auth:sanctum` with throttling applied.

**Updater path traversal.** The updater endpoint was probed with traversal
sequences in the version and platform parameters. All 404.

**Transfer request token leak.** Previously fixed; re-probed to confirm the fix
still holds. No token appears in any response.

**SQL injection.** Every query builder call site was reviewed. All user input
is parameterised. The only raw fragments are `orderByRaw` with no user input,
plus F-4 above.

**Mass assignment.** Every model declares `$fillable` or `$guarded`. None
leaves both unset.

**Search XSS.** The search highlighter escapes first, then substitutes sentinel
characters (`\u{0002}` / `\u{0003}`) for the highlight markers — so a match
inside user input cannot introduce markup.

**Profile permission gating.** `RestrictsToServerAdmins` refuses by URL as well
as hiding from navigation. Covered by `ServerAdministrationAccessTest.php`,
whose teeth check distinguishes a 403 from a mere 200-that-is-not-linked.

---

## Outstanding

Nothing from this review is left unfixed.

F-6 carries the process lesson: **a flaky test cannot be bisected with one run
per side.** Reverting a change and seeing green is meaningless when the test
fails four times in five on either side, and it very nearly produced a confident
wrong conclusion here.

F-5 is worth remembering as a *shape* rather than a bug: two guards, each
demonstrably correct and each with a real failure behind it, that together
prevent a state from ever being written. Neither reads as wrong at its own call
site, and the code review that added the second one would have had no reason to
look at the first.

`/app/music` at 36 queries is bounded and does not scale with row count
(`ListPageCostTest`), but it is not obviously *minimal*. Worth a look, though
it is no longer the page anyone notices.

## Verification

- Full PHP suite: **526 passed**, 1,148 assertions.
- Vitest: **98 passed**.
- Playwright, **every project** (desktop, mobile, shell, uploads):
  **306 passed, 0 failed**, 4 skipped.
- The home page was rendered against the real 8,338-item library: 200,
  **26 queries**, three rails, 36 images, and subtitles resolving from all
  three metadata tables present (music 20/20).
- Every fix above has a test verified to fail when the fix is reverted.
