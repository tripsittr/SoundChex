# 035 — A queue written once

**Merged** 2026-09-09 · **Issues** S-105, S-106, S-110, S-111, S-112, S-113, S-53, S-04, S-108, S-109

The songs page was 3 MB of HTML for 48 songs, and the pages around it ran a
query per row for things that cannot change within a request. Both are fixed:
`/app/music` is now 544 KB and 57 queries, against 3,027 KB and 344.

## What changed

### The songs list writes its queue once

`song-row` carried `data-play="{{ $payload->toJson() }}"` on both of its play
controls — the artwork and the title — and `browse.blade.php` passed it the
whole 48-track queue. So the same 26 KB of HTML-escaped JSON was serialised 96
times into one page: **2,507 KB of the 3,027 KB, 83% of it**, and a 26 KB
`JSON.parse` on every tap.

The list now writes the queue once into `data-play-queue` on the element that
holds it, and each row carries only `data-play-index`. `queueFor()` in
`now-playing.js` resolves the nearest ancestor carrying one.

Named `data-play-queue` rather than `data-queue` because `track-menu.blade.php`
already uses that name for the single track a menu acts on — and the menu sits
*inside* each row, so `closest('[data-queue]')` from a row button would have
found the menu's one track and played that instead of the list.

`data-play` still works and is still correct for a genuinely single-track
control: the poster's hover-to-play button, and the play button on the offline
shell's item screen. Nearest wins, so a control with its own `data-play` still
means itself even inside a list.

Same fix on `album.blade.php`, `playlist.blade.php` and `artist.blade.php`. The
artist page has two different queues — Singles and Appears on — so the
attribute sits on each `<ol>` rather than on a wrapper around both.

**And in the offline shell, which had the same bug.** `render.js`'s `songRow()`
embedded the queue per row too — its docblock said it did so "exactly as the
server does it", which was true and is why it inherited the fault. Sixty rows
with two controls each was 120 copies built into the DOM, on the phone, which
is where it costs most. `songList()` now returns `{ rows, queue }` and the two
callers in `offline-shell.js` hang the queue on the `<ol>`. The genuinely
single-track button on the item screen keeps its own `data-play`.

### Four N+1s

Found by grouping each page's queries by shape rather than by reading code:

- **`CurrentProfile` was never a singleton.** Twenty call sites do
  `app(CurrentProfile::class)`, and each built its own instance with an empty
  cache — 126 identical profile lookups on `/app/music`, 28 on `/app`, 85 on
  `/app/genres`.
- **`resumePosition()` queried per track.** `playerPayload()` calls it, and a
  queue builds a payload for every row: 121 queries. It now answers from an
  already-loaded `plays` relation when there is one, and `MediaBrowser::base()`
  eager-loads it.
- **`track-menu.blade.php` fetched the playlist list per row** — 48 identical
  queries for a list that cannot change while a page renders. Wrapped in
  `once()`.
- **`MediaItem::find()` inside a loop** on `albums`, `artists` and the artist
  page's album grid, once per cover: 60 and 100 lookups. Now one `whereIn`
  before the loop.
- **The queue builders did not eager-load `plays` either.** `AlbumBrowser`'s
  three track queries, the shuffle endpoint, the playlist page and
  `MediaItem::albumQueue()` all loaded `musicMetadata` alone, so each still
  asked per track. The album page ran **50 queries for a 14-track album** and
  the item page **63**; the largest album here is 49 tracks.

Measured against a copy of the live catalogue:

| | Before | After |
| --- | --- | --- |
| `/app/music` | 344 queries, 3,027 KB | **57 queries, 544 KB** |
| `/app/albums` | 68 queries | **4** |
| `/app/artists` | 108 queries | **4** |
| `/app/genres` | 205 queries | **46** |
| `/app/album` (14 tracks) | 50 queries | **6** |
| `/app/artist` | — | **7** |
| `/app/shuffle` | — | **3** |
| `/app/item/{id}` (music) | 63 queries | **15** |

### The space gate asked the wrong number, and said yes when it did not know

`checkSpace()` answered an unknown quota with `fits: true`. A platform that
reports nothing — which is the platform downloads exist for — filled the device
without a word. It now fails closed and asks.

The three call sites in `download-button.js` had drifted into three different
sentences for the same situation, and none handled a missing figure. They share
`confirmSpace()` now, which logs every outcome with its numbers. A third state
was added: **tight** — it fits but leaves little, so warn rather than refuse. A
nearly-full device is the user's call to make, not something to discover later.

**A reserve sized for a disk broke three specs.** The first version used 2 GB,
reasoning that a phone with less is not a working phone. The e2e browser
reports a **1,048,576,000-byte quota with 0.98 GB free**, so 2 GB refused every
download and `download-queue` went red three ways. The reserve is quota-scale
(64 MB) because a quota is not a disk: on iOS it is bounded by the ~1 GB
IndexedDB cap and says nothing about a 128 GB phone. The device-scale check
needs a real reading and arrives with native storage (S-107 step 2a).

That reading was proven possible first rather than assumed: a `statvfs` call
returns **7.1 GB free of 494.4 GB** here, matching `df -k` exactly. Worth
knowing — two struct layouts before it returned 1.2 trillion GB, because on
Darwin the block counts are 32-bit while the block *sizes* are 64-bit. A wrong
number there refuses everything or approves everything, silently.

### Four "flaky" specs were not flaky, and two were app bugs

`download-logging:36`, `download-queue:90`, `phone-dl:200` and
`library-refresh:19` each failed **alone, on a clean tree**, on WebKit. Three
were filed under S-04 — "fails only in a full run" — which is exactly why
nobody had looked at them.

Two were genuine defects a user would hit:

- **A queued download silently never ran.** `drain(run)` took one runner and
  applied it to everything still queued, so whichever `enqueue()` happened to
  start the drain decided how *every* later item was fetched. Tap one track,
  tap a second while the first is going, and the second downloads with the
  first's runner — or, when the first came from somewhere else entirely, does
  not download at all. The runner now travels with its entry.
- **A finished download looked untouched.** The click handler wrote `stored`
  onto the button it captured at click time, but a library refresh replaces
  every row through `main.replaceChildren()` — so on a page that refreshed
  mid-download the write landed on a detached node. The file was on the device
  and the icon said it was not. Both the success and failure paths now re-find
  the live button. `paintIconDownloadStates()` also no longer resets a button
  mid-transfer, which was erasing "number 2 in the queue".

The other two were tests measuring the wrong thing: one raced the launch sync
(a *full* sync on a fresh database, which legitimately redraws) and blamed its
own synthetic event; the other asserted on a diagnostics buffer that
`sessionStorage` carries between tests, where `record('test:reset')` only
*appends* an event and never cleared anything.

**And underneath all of it, one line of config.** The `mobile` project ran with
Playwright's default `serviceWorkers: 'allow'`. A worker controls this origin,
and requests it answers on the page's behalf reach neither `page.route()` nor
`context.route()` — so a test that aborted `/app/downloadable` watched the
listing succeed and saw `requestfinished` fire for a request its own handler
had never been consulted about. That reads as a timing problem, which is why
three attempts at it were wrong before the fourth looked at *interception*
rather than *timing*. Now `serviceWorkers: 'block'` there;
`service-worker.spec.js` runs on desktop and keeps them.

S-04 is down from seven claimed members to four verified ones.

### "Download all" left every row on the idle arrow

`runBatch()` marked only the batch button. Downloading an album or the whole
library left each song row showing the idle download arrow until its own file
landed, so a list of a hundred rows said nothing was happening for as long as
it took. Rows are now marked `downloading` up front and `stored`/`failed` as
each finishes.

Two ordering bugs came with it. `paintIconDownloadStates()` reads IndexedDB —
where a *failed* track simply is not — so running it after the final states
returned every failed row to `idle`, and the batch button with it: a run in
which nothing downloaded read as one that had never been asked for. The repaint
now runs *before* the final states, and failures are re-marked after it. And
the batch button was held by reference, so a library refresh mid-batch detached
it and the completion state went nowhere — the same bug as the row buttons
above, in a second place.

Persistence was verified rather than assumed: the stored icon survives SPA
navigation, a full reload, and a new page in the same context. That is what
stops the same file being downloaded twice.

### Moving the repository broke the services and the iOS build

Found while starting the server. All three launchd plists — installed *and* the
repo's own templates — named `/Users/tripsittr/Documents/GitHub/SoundChex`,
which the project has moved out of. `serve` and `scheduler` were restart-looping
into a 4.6 MB log nobody reads, and the server had simply been down.

The same move broke the iOS build less visibly: cargo bakes absolute paths into
`src-tauri/target`, so it failed with `failed to read plugin permissions: ...
app_hide.toml: No such file` — naming a permissions file rather than a stale
cache, which reads as a Tauri problem. Fixed by deleting the iOS target (3.2 GB)
and the project's DerivedData. No tracked file carries the old path.

### S-53 moved to Done

The third IndexedDB database was fixed on 22 August in `a82fc83` and the entry
was never moved. Reading the code found the recovery already there, covered by
`tests/js/downloads-recovery.test.js`. The entry was the stale thing, not the
code.

## Worth knowing

- **The singleton needed a cache key, and the first one was wrong.** Keying on
  the token *id* passed locally and failed
  `test_two_profiles_do_not_share_a_cache_entry`: Sanctum's test double reports
  the same id — `false` — for every token, so a second profile read the first
  one's cached answer. That is a capped device seeing an uncapped library, so
  the key is the token object's identity instead. `switchTo()` and `forget()`
  clear the key as well as the profile.
- **No migrations, no data rewritten.** Markup, a service registration and
  seven eager loads.
- **Eight Playwright selectors changed** with the markup they assert on. A row
  is now `[data-play-index]`; posters are still `[data-play]`; the shared
  helpers select both.

## Still wrong

- **The PDF reader is untouched and still slow.** pdf.js always issues one
  un-ranged GET before deciding whether to range, so the whole file arrives
  regardless — `disableAutoFetch` and `rangeChunkSize` were tried against the
  real 5.4 MB `The Hobbit.pdf` and every configuration measured *worse* than
  the default (6.0 MB → 7.0–8.0 MB). Reverted. Linearising does not help
  either: `PiHKAL.pdf` already is, and still downloads whole. The remaining
  levers are precaching the 2.2 MB worker and showing real progress.
- **`playbackSize()` still stats the filesystem per row** in `track-menu` —
  12.4 ms for 48 tracks here, and the value is genuinely used for the
  pre-download space warning, so it was left alone rather than changed blind.
- **`/app` still runs 141 queries.** The rails each eager-load their own
  batch, which is correct per rail but repeated across eight of them. Not
  addressed here.
- **`embedded-shell.spec.js:341` was failing on `main` too, and is now fixed**
  (S-109). `server.html` hardcodes `ORIGIN` to port 8000 — right for a real
  install, not where the e2e server listens — so the redirect fired and landed
  on nothing while the spec waited for `/admin`. The destination is stubbed
  now. The teeth check then found the test asserted nothing: with the
  load-time redirect deleted it still passed, because the page also redirects
  from a 5s poll and the wait was 15s. Tightened to 2s.

## Tests

- **PHP 481/481** — 6 new in `tests/Feature/ListPageCostTest.php`, which count
  queries and assert the payload shape rather than timing anything. Each was
  checked for teeth: reintroducing the per-row payload turns the first red (24
  copies found), removing the singleton turns two red (64 queries, and two
  different instances), restoring `find()` in the albums loop turns the fourth
  red (15 queries), and dropping the `plays` eager-load turns the fifth and
  sixth red (47 and 28 queries).
- **Vitest 98/98** — 4 new in `tests/js/library-song-list.test.js` and 6 in
  `downloads.test.js`, neither of which had covered `songList()` or
  `checkSpace()` at all. Checked for teeth: restoring the per-row queue turns
  "leaves no copy of the queue on any row" red, restoring `fits: true` turns
  "asks rather than assumes when no figure is available" red, and shrinking the
  reserve turns "keeps a reserve" red.
- **Playwright 288 passed, 2 failed** on the final tree — up from 284/6, and
  the whole `mobile` project is **77/77** on its own. Both remaining failures
  are named S-04 members, each verified today to pass alone and fail only in a
  290-test run: `downloads-batch:20` (5/5 alone) and `player-session:85`
  (4/4 alone). That is the shared-database constraint in S-16, not a
  regression here.

