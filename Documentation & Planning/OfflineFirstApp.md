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

### Phase 1 — API foundation (~4 days)

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

### Phase 2 — device mirror (~4 days)

- IndexedDB schema for items, metadata, genres, people.
- Sync on launch, on foreground, and on demand; delta after the first pull.
- A local query layer: filter by type, genre, sort, paginate — everything
  `MediaBrowser` does on the server, done against the mirror.
- Title/person/tag search against the mirror.
- Conflict rule: **server wins for catalogue data, device wins for playback
  progress.** Progress is the only field the device legitimately knows better.

### Phase 3 — client-rendered UI (~2 weeks)

The bulk of it. Port 14 templates, screen by screen, each fed by the mirror:

1. Home rails, item detail, type grids
2. Search
3. Watch and reader entry points
4. Downloads page — already partly client-side

Existing player, download and reader JS is reused rather than rewritten.
**Each screen ships behind a flag**, so the Blade version stays until its
replacement is proven.

### Phase 4 — write queue (~3 days)

- Queue progress, watchlist and ratings while offline; replay on reconnect.
- Idempotent writes, so a replayed queue cannot double-apply.
- Annotations already have an API; they join the same queue.

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
