# Status

What exists, what doesn't, and what's next. This is the file to read first.

**The admin Tracker is the list.** Every feature, fix and bug lives in the
landing site's admin panel (`SoundChexWebsite` repo → `/admin` → Tracker), not
in `Issues.md`, which is now only a pointer to it. A finished plan is deleted
once its tracker item records what was built and what verified it — a plan kept
after the work is done is a second, staler answer to a question already
answered.

What remains here is either an unstarted plan or reference:

- `Issues.md` — everything tracked, and where each got to
- `Status.md` — this file
- `Handoff.md` — current state, known issues, what was tried and abandoned
- `UsersAndProfiles.md` — the account and profile model
- `RemoteAccess.md` — reaching the server from outside the house
- `NativeClients.md` — whether to build native apps, and the case each way
- `AppDistribution.md` — sideloading against stores, and what each costs

---

## Built

Each of these is working against real data in the current library
(~1,450 items: music, one film, a dozen books).

**Catalog** — one schema across music, films, TV and books. Type-specific
metadata reached generically through `metadata()`. Tags, people, collections.

**Library management** — scheduled scanning of watch folders, filing into
`Artist/Album` and `Title (Year)` trees, and duplicate detection by content
hash. Duplicates default to review; merging re-verifies both files immediately
before deleting one.

**Metadata enrichment** — TMDB (film and TV), Open Library, MusicBrainz,
AcoustID, iTunes, Spotify. Keys stored encrypted and entered from the admin
panel. Every run snapshots the previous state, so a provider revising its own
record is recoverable.

**Video** — transcoding to web-playable copies with hardware encoding, caption
tracks from embedded streams, sidecar files and OpenSubtitles, skip markers,
and a full-screen player with custom caption rendering.

**Books** — EPUB, PDF and CBZ reader with highlights and private notes; OCR of
scanned pages with word boxes so scans are selectable; illustration and outline
extraction; full-text search across every page.

**Profiles** — per-person history, resume points, highlights and watchlist,
with a kids mode that caps ratings across browse, search and direct links.

**Remote access** — reachable over Tailscale at
`https://macbookair.tail7e590c.ts.net` with a real certificate, from any device
on the tailnet. This is what makes the offline features work: a secure context
is required for service workers, add-to-home-screen and durable storage.

**Search** — one query across titles, metadata, cast, dialogue and the text
inside books. A dialogue hit jumps to the moment it is spoken; a book hit opens
at the page.

**Uploads** — an `uploader` role that reaches only the upload page, so adding
files does not require admin rights. Bulk upload lands in the inbox and is
catalogued, identified and filed by the existing pipeline.

**Admin** — resources for every media type, duplicate review, metadata and
library settings, profile management, dashboard widgets. Themed to match the
media center, sharing one palette defined in `resources/css/tokens.css`. Every
screen, the dashboard included, is gated on the current profile's permissions.

**Tests** — 266 PHP, 51 Vitest, 231 Playwright, all green. Every guard was
verified by breaking it on purpose and confirming a test fails. Writing them
found five silent bugs: profile permissions blocked at the panel door, music
still stopping on navigation, episode codes stripped as file extensions, an
ungated admin dashboard leaking titles above a profile's rating, and a missing
IndexedDB record read as a hit. Recorded as S-70 in `Issues.md`.

---

## Not built

Roughly in the order they're worth doing.

### Native offline bridge

Downloads stop when the app is backgrounded, audio eventually does too, and
IndexedDB caps the library at about a gigabyte — none of which JavaScript can
fix. `NativeOfflineBridge.md` keeps the Tauri UI and moves only those three
things into a native plugin, rather than rewriting every screen in Swift.
Depends on `NativeDownloads.md` for where the bytes land.

**No longer blocked.** The offline failures were diagnosed and fixed on
21 August 2026 — an artwork request flood, a dead connect screen, and a
downloads view reading the wrong IndexedDB store. All application logic, none of
it what this plan addresses, which means the three platform limits it targets
are still real and still unaddressed.

### Cross-media links

A novel pointing at its film adaptation and soundtrack. Deferred from
unified search (S-56) until the library holds a book and its adaptation together —
a matching rule written against one film verifies nothing.

### HLS adaptive streaming

Currently one fixed-bitrate MP4, which buffers or fails on cellular and over a
tunnel. Needs a rendition ladder, segment storage, playlist generation and
player integration. Roughly doubles disk per title. **The largest remaining
item by some margin.**

### Remaining metadata sources

Registered in `config/metadata_sources.php` but unwritten. The pipeline skips
missing classes, so nothing is broken by their absence.

Discogs · Last.fm · Genius · Deezer · OMDb · Trakt · TVMaze · TVDB ·
Google Books · LibraryThing · Fanart.tv

---

## Known gaps and caveats

**The rating cap does not apply to music.** `ContentGate` filters on movie
`mpaa_rating` and show `content_rating`; music has no certification column, so
a capped profile sees every track. Found by sabotage — removing the gate from
the shuffle endpoint broke no test, because there is nothing in a music-only
queue it could have blocked. The gate stays in those queries so the day music
gains a rating, no endpoint is the one that skipped it. Explicit-lyrics
filtering would need a new column and a rule in the gate.


- **No TV shows in the library**, so that path is largely unexercised. The
  schema, TMDB show source and `content_rating` column exist but have not run
  against real episodes.
- **No TMDB key means no film metadata.** The key lives in settings, not env,
  and the source skips itself silently when it is missing — so films are
  "enriched" with nothing and no error is raised. Lost once already, when the
  database was rebuilt. Every film added while it was empty has no year, and
  without a year the conversion filer refuses to file rather than dropping a
  film into the library root.
- **Avatars are host-local.** They live on the public disk and are gitignored,
  so they don't travel with the repo.
- **Herd may serve PHP 8.3** while dependencies need 8.4+. The CLI is 8.4.13;
  if the site errors where the CLI doesn't, check the Herd GUI.
