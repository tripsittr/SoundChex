# Offline-first app

Make the whole app usable with no network — browsing, search, reading and
playing downloaded media — instead of only the downloads shelf.

## Why this is smaller than it sounds

Three measurements taken before scoping, because they change the shape of the
work:

- **Roughly half the API already exists.** The reader (18 JSON responses),
  annotations (9), subtitles (14) and watchlist (3) are already JSON endpoints
  the frontend calls with `fetch`. Only **11 view-returning methods** need API
  equivalents, and 6 of those are `MediaCenterController`.
- **Sanctum is already installed** (`laravel/sanctum ^4.0`, `HasApiTokens` on
  `User`). Token auth is configuration, not a build.
- **The catalogue is tiny.** Measured, not estimated: all 1,450 items with
  their metadata serialise to **0.8 MB of JSON — 76 KB gzipped**. The whole
  library transfers in well under a second, so the device can hold a
  *complete* copy rather than a cache of recent things. This is the fact that
  makes real offline browsing possible, and it has a lot of headroom: even a
  20,000-item library would be about 1 MB compressed.

The expensive part is not the API. It is that **3,400 lines of Blade across 14
templates** currently render the UI server-side, and offline-first means the
device renders instead.

## Media is never downloaded automatically

**The user chooses what lives on the device. Nothing else does.**

Only the *catalogue* syncs automatically — 76 KB of titles and metadata, which
is what makes browsing work offline. Actual media files are downloaded on an
explicit tap and never in the background, never on wifi "helpfully", never
because something was played once.

This is a rule, not a default to be revisited:

- A media library is large and personal. Filling someone's phone with films
  they did not ask for is a real harm, not an inconvenience.
- On a metered connection an automatic download costs money.
- The existing download button already works this way; the offline work must
  not quietly change it into a sync-everything feature.

The corollary is that **the app must say clearly what is and is not available
offline** — a greyed-out item with "not downloaded" is honest, whereas an item
that looks playable and then fails is not.

## What "usable offline" means here

Honest boundaries, decided up front so the plan can be judged against them.

**Works offline:**

- Browse the full library — every type, genre rails, item detail pages
- Search titles, people, and tags
- Play any **downloaded** track or film
- Read any **downloaded** book, including highlights and notes
- Watchlist toggles, playback progress, ratings — queued and synced later

**Cannot work offline, by nature:**

- Playing or reading anything not downloaded. The bytes are on the server.
- Artwork not yet cached
- Metadata enrichment, subtitle search, scanning, uploads — all server work
- Dialogue and page-text search. 2,879 cues and 1,346 pages are a different
  order of data from the catalogue, and full-text indexes on device are a
  project of their own. **Deliberately excluded.**
- The admin panel. Filament is server-rendered and stays online-only.

## The decision that shapes everything

Two ways to reach offline-first, and they are not close in cost:

### Option A — API + client-rendered app (recommended)

Move the 14 media-center templates to client-side rendering, fed by the API,
with the catalogue mirrored into IndexedDB.

- **Genuinely offline.** Nothing needs the network to render.
- Keeps one frontend. The existing 4,742 lines of JS — player, downloads,
  reader — are already client-side and mostly port as-is.
- The Blade templates get deleted, not duplicated, so there is no second
  implementation to keep in step.
- **Cost: ~3–5 weeks.**

### Option B — cache-only, keep Blade

Aggressively cache rendered HTML in the service worker.

- Much cheaper, ~1 week.
- **But it is a lie under pressure.** Cached HTML goes stale, shows a library
  that no longer matches, and any page never visited while online simply does
  not exist offline. It also cannot filter or search offline, because that
  work happens on the server.
- Rejected. It would look finished and fail exactly when relied on.

**Recommendation: Option A.** Option B is the cheap version of a different,
worse product.

## Phases

Ordered so each one ships something usable, and so the risky part comes early.

### Phase 1 — API foundation — **DONE**

- Sanctum token issue/revoke; the Tauri shell stores the token, not a session
  cookie. Session auth over a `tauri://` origin is exactly the CORS problem
  that already broke the connect screen.
- CORS config for the app origin.
- `GET /api/v1/library` — the whole catalogue, ETag'd, gzipped. Measured at
  0.8 MB raw, 76 KB compressed for the current library.
- `GET /api/v1/library/delta?since=` — changes only, so a resync is cheap.
- Resource classes so the JSON shape is deliberate rather than a leaked model.
- **The rating cap and profile permissions must be enforced in the API**, not
  just the UI. A capped profile must not receive an R-rated item in its sync
  payload at all — this is where an offline copy could otherwise leak the
  thing `ContentGate` exists to prevent.

**Built and pushed.** `POST /api/v1/tokens`, `GET /api/v1/me`,
`DELETE /api/v1/tokens/current`, `GET /api/v1/library`,
`GET|POST /api/v1/library/delta`. 13 tests, both leaks below verified by
sabotage.

Real numbers, measured rather than estimated: **1,454 items, 0.61 MB raw,
94 KB gzipped**. No `file_path` in the payload.

Two leaks the tests caught before this shipped, both worth remembering:

- `CurrentProfile` read `$token->abilities` directly. That array is not
  populated on the token double Sanctum uses in tests, so the profile
  resolved to null, no cap applied, and a capped device would have received
  the whole library. It asks through `tokenCan()` now.
- The controller injected `ContentGate` and `CurrentProfile` in its
  constructor, which runs *before* the auth middleware. The cached profile
  was null, so the gate filtered nothing **and** the ETag carried profile 0 —
  a capped device could 304 its way into keeping what it should have lost.
  Both resolve per call now.

Deletion and a tightened cap share one mechanism: the device sends
`known_ids` and gets back what it may no longer hold. A delete-only list
would leave a newly blocked film playable on a child's device.

### Phase 2 — device mirror — **DONE**

- IndexedDB schema for items, metadata, genres, people.
- Sync on launch, on foreground, and on demand; delta after the first pull.
- A local query layer: filter by type, genre, sort, paginate — everything
  `MediaBrowser` does on the server, done against the mirror.
- Title/person/tag search against the mirror.
- Conflict rule: **server wins for catalogue data, device wins for playback
  progress.** Progress is the only field the device legitimately knows better.

**Built and pushed.** `resources/js/library/` — `mirror.js` (IndexedDB),
`sync.js` (full pull, delta, sign-out), `query.js` (pure query layer),
exposed as `window.soundchexLibrary`. 23 Vitest cases and 6 browser cases.

Its own database, not a new version of the downloads one: bumping that runs
an upgrade transaction across a store holding gigabytes of media blobs, and
a failure there loses files the user chose to keep.

Two rules worth keeping:

- **A full sync replaces; a delta carries removals.** Merging would leave an
  item the server has stopped sending — deleted, or newly blocked by a cap —
  on the device forever. Deletion and a tightened cap use one list, because
  the device cannot tell them apart and must act identically on both.
- **Sign-out and profile switch clear the mirror.** It holds exactly what one
  profile may see, so carrying it across would let a capped profile browse the
  previous one's library offline, where no server check applies.

Sync runs on launch and on returning to the foreground — a phone suspends a
page rather than closing it, so an app left open would otherwise show a
day-old library with nothing to say it was stale.

### Phase 3 — offline browsing — **DONE**

The bulk of it, but smaller than first scoped.

The original estimate counted all 3,326 lines of Blade in the media center.
That was wrong: **1,922 of them should not be ported at all.**
`reader.blade.php` (1,373 lines) mounts epub.js or pdf.js against the
document, `watch.blade.php` (519) owns the viewport for video and subtitles,
and `downloads.blade.php` (30) is already client-side. All three are already
excluded from SPA navigation for that reason, and they stay server-rendered.

That leaves **1,404 lines across 12 templates**, several of them trivial —
genres is 22 lines, albums 45, artists 46. The real cost is `show.blade.php`
at 396 lines, four media types of conditional logic, and the fact that this
replaces working code: "as good as before" is the floor, not the goal.

Ported screen by screen, each fed by the mirror:

1. Home rails, item detail, type grids
2. Search
3. Watch and reader entry points
4. Downloads page — already partly client-side

Existing player, download and reader JS is reused rather than rewritten.
**Each screen ships behind a flag**, so the Blade version stays until its
replacement is proven.

**Built and pushed.** Songs, films, shows, books, albums, artists, search and
the item page all rebuild from the mirror when the server cannot be reached.

Taken as **progressive enhancement, not a rewrite**: online, Blade renders
every page exactly as before and none of this runs, so the path used every day
cannot regress. Rendering client-side always would make the first paint wait
on JavaScript and IndexedDB even when the server is on the same network and
faster — the wrong trade for a self-hosted app.

The gap that made none of it work at first: the service worker serves
`offline.html` on a failed navigation, and that page loaded **no scripts**, so
the mirror was unreachable and offline was a dead end with the whole catalogue
sitting on the device. It now imports the library bundle, resolving the
content-hashed filename from Vite's manifest rather than hardcoding one that
goes stale on the next build.

Three rules worth keeping:

- **Reachability is checked against the server, not `navigator.onLine`.** Over
  Tailscale a phone can be on wifi with the tailnet unreachable, which is a
  different question entirely.
- **The takeover refuses to run on a page that already has content.** Swapping
  live data for a snapshot is a downgrade, not a rescue.
- **Every offline screen says it is a local copy**, and search says what it
  cannot search. A library that is quietly a snapshot looks like one that has
  lost things.

Still server-rendered, deliberately: the reader and the watch page mount their
own renderers and own the viewport, and downloads was already client-side.

### Phase 4 — write queue — **DONE**

- Queue progress, watchlist and ratings while offline; replay on reconnect.
- Idempotent writes, so a replayed queue cannot double-apply.
- Annotations already have an API; they join the same queue.

**Built and pushed.** `resources/js/library/write-queue.js`, plus the server
guards that make a replay safe.

Only writes the device can legitimately decide alone are queued: position,
watchlist, ratings. Anything needing the server to answer is not queued,
because a queued request whose result the user is waiting for is just a
request that failed slowly.

Two server changes were needed, and neither was optional:

- **Progress now carries `recorded_at`** and is refused if older than what is
  stored. Without it, reconnecting after a drive replays an hour-old position
  over the episode being watched now — the device silently undoing the user's
  own progress.
- **The watchlist takes a stated result rather than toggling.** A toggle is
  not replayable: sent twice it returns to where it started, and a retry after
  a timeout is indistinguishable from a first attempt.

**A real bug surfaced while testing this.** `profile_id` was missing from
`MediaPlay::$fillable`, so it was silently dropped on every create. The column
stayed null, the "reuse this session's row" lookup filters on it and never
matched, and each position update wrote a **new row**. In the live library:
1,062 of 1,074 play rows had a null profile, and 27 items had accumulated more
than three rows each. Two people sharing a login were also not keeping separate
places in the same film, which is what the column exists for.

### Phase 5 — tests and cutover (~4 days)

Per the testing standard, and this is not optional given what is being
replaced:

- PHP: API contract, and **the rating cap on the sync payload** — a capped
  profile's sync must not contain what it must not see, tested by direct
  request.
- Vitest: the local query layer, delta merge, conflict resolution.
- Playwright: **the whole app offline**, network cut — browse, search, open a
  detail page, play a download, read a book, queue a write and see it replay.
- Delete the Blade templates only once their replacements pass.

## Total: 4–5 weeks

Slower than it looks on paper because Phase 3 is real UI work and the existing
Blade is good — this replaces working code, so "as good as before" is the
floor, not the goal.

## Risks

- **Phase 3 is where estimates break.** 3,400 lines of Blade includes layout
  subtleties, the responsive grid and the persistent player, all of which took
  iterations to get right. Porting is not transcription.
- **The rating cap is the dangerous part.** A sync payload is a bulk export;
  getting the gate wrong there leaks an entire capped library at once rather
  than one page. It needs its own tests and a sabotage check.
- **Two sources of truth during migration.** Mitigated by the flag per screen
  and by deleting Blade as each screen lands.
- **iOS storage.** 2 MB of catalogue is nothing, but downloads plus artwork
  are not. The existing quota checks stay relevant.

## What this does not fix

**Online browsing will still cost a relay round trip** while Tailscale Funnel
is on — though after the first sync, most navigation stops needing the server
at all, so the practical effect is that the app feels local either way.

## Not started

Nothing in this document is built. The current app is a thin Tauri shell around
the live site, with offline limited to downloaded files and the downloads page.
