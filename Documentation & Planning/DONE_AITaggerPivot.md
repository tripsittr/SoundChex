
# Media Catalog & Metadata Tagger — Full Product Plan

## Vision

A self-hosted personal/family media catalog for organizing music, movies, TV shows, and books. Runs on a home computer, accessible from any device anywhere via browser. No subscriptions, no cloud dependency, no AI required.

**Phase 0 target**: one person, one machine, local network.
**Long-term ceiling**: multi-user household server reachable from anywhere, native mobile apps, light transcoding, plugin system.

The model is Emby/Jellyfin/Plex — one server, one library, many accounts. Not a
SaaS. Decisions should keep that ceiling reachable without a rewrite.

---

## App Architecture Decision

**Web app (Laravel/Filament) + PWA. No native wrapper for MVP.**

| Approach                | When it applies                                                                   |
| ----------------------- | --------------------------------------------------------------------------------- |
| Browser (web app)       | All remote clients — phones, tablets, laptops, smart TV browsers                  |
| PWA install             | "Install" on phone/tablet home screen — app-like feel, no App Store               |
| Tauri desktop companion | Phase 6+ — server-side folder watcher, bulk import UI on the host machine         |
| React Native / Flutter  | Only if native push notifications or device camera (barcode scan) become critical |

The server runs on one machine. Every other device just opens a URL. There is nothing to install on clients.

---

## Deployment Model

### Phase 0: Local network only
- Laravel on a home Mac/Linux machine (Herd or Nginx + PHP-FPM)
- SQLite database
- Files on local disk
- Accessible at `http://192.168.1.x` or `http://media.local` on LAN

### Phase 1: Remote access (no code changes to the app)
Three options in order of simplicity:

**Cloudflare Tunnel** (recommended, free)
- Install `cloudflared` daemon on the home server
- Tunnel routes `yourdomain.com` → home server through Cloudflare's edge
- No port forwarding, works even if ISP blocks 80/443, SSL automatic
- Setup: ~1 hour

**Tailscale** (most private, free personal tier)
- Mesh VPN — install on server + each client device
- No public internet exposure
- Access via Tailscale IP from anywhere
- Setup: ~30 minutes

**VPS reverse proxy** ($5/mo Hetzner/DigitalOcean)
- VPS holds the public IP; WireGuard tunnel to home server
- Best uptime if home internet is unreliable
- Setup: half a day

### Not planned: hosted SaaS tier

SoundChex is self-hosted software, full stop. Multi-tenancy was removed
outright (see **Decisions Since This Plan Was Written**), so a hosted tier
would be a rebuild rather than a config swap. Remote access is a deployment
concern, not a schema one — Phase 1 above covers it.

---

## What the Current Codebase Gives You (keep all of it)

| Existing Feature            | Role in New Product                          |
| --------------------------- | -------------------------------------------- |
| Auth + roles                | Owner / admin / member on one shared library  |
| Filament admin panel        | Library management at `/admin`                |
| Queue/jobs infrastructure   | Powers async metadata enrichment jobs         |

---

## Decisions Since This Plan Was Written

The plan below predates three decisions that changed the architecture. Where
the two disagree, this section wins.

**1. Multi-tenancy removed.** SoundChex follows the Emby/Jellyfin/Plex model:
one server, one library, many user accounts. The `organizations` tables,
invites, tenancy middleware, and Spatie team scoping were all dropped. Any
`organization_id` in the data model below no longer exists.

Remote access from phones on other networks is unrelated to tenancy — it comes
from Cloudflare Tunnel, Tailscale, or a reverse proxy (Phase 1).

**2. Two surfaces, not two panels.** The Filament *customer* panel was retired.

| Path     | What it is       | Who it's for                     |
| -------- | ---------------- | -------------------------------- |
| `/app`   | **Media center** | Everyone — browse and play       |
| `/admin` | Filament panel   | Owner/admin — manage the library |

The media center is a standalone Blade + Alpine + Tailwind v4 app with a dark
streaming-style UI: top nav, hero banner, poster rails, full-bleed detail
pages. Members never see a table view.

**3. API keys live in the database, not `.env`.** Keys are stored encrypted in
the `settings` table and resolved through `SettingsService`, so they're
editable from the Settings UI without touching files. Never read them via
`config()` or `env()` inside a source.

---

## Metadata API Coverage

### Music — Enrichment Pipeline (layered, each pass adds more data)

| Layer | Service                    | What it provides                                                              | Auth           | Cost             |
| ----- | -------------------------- | ----------------------------------------------------------------------------- | -------------- | ---------------- |
| 1     | **File tags (getID3)**     | Title, artist, album, BPM, key, ISRC, duration, cover art, sample rate        | None           | Free             |
| 2     | **AcoustID + Chromaprint** | Audio fingerprint → identify completely untagged files by audio content       | Free API key   | Free             |
| 3     | **MusicBrainz**            | Canonical IDs, release groups, labels, recording relationships, ISRC          | None           | Free             |
| 4     | **Discogs**                | Physical release details, format, pressing, catalog number, marketplace value | OAuth          | Free tier        |
| 5     | **iTunes Search API**      | High-res artwork, Apple catalog ID, genre, track preview URL                  | None (no auth) | Free             |
| 6     | **Spotify Web API**        | Audio features: danceability, energy, valence, tempo, key, loudness, mode     | OAuth          | Free             |
| 7     | **Last.fm**                | Community tags, similar artists, play counts, listener counts, biography      | API key        | Free             |
| 8     | **Fanart.tv**              | High-quality artist artwork, album art, banner images, logos                  | API key        | Free             |
| 9     | **Genius**                 | Lyrics, annotations, song meanings, featured artists                          | API key        | Free             |
| 10    | **MusixMatch**             | Lyrics (fallback), synced lyrics (LRC format)                                 | API key        | Free tier        |
| 11    | **Deezer API**             | Alternate catalog ID, BPM, explicit flag, preview, contributor list           | None           | Free             |
| 12    | **Beets (local)**          | Tag correction, duplicate detection, filename organization rules              | None           | Free/open source |

**Audio fingerprinting flow** (Layer 2 detail):
```
1. Run fpcalc (Chromaprint CLI binary) on the audio file → generates fingerprint + duration
2. POST fingerprint to AcoustID API → returns MusicBrainz Recording IDs
3. Use Recording ID to fetch full metadata from MusicBrainz (Layer 3)
4. Populate tags on the sample — even completely untagged files get identified
```
`fpcalc` is a standalone binary (no Python), callable from Laravel via `Process::run(['fpcalc', $filePath])`.

---

### Movies — Enrichment Pipeline

| Layer | Service       | What it provides                                                            | Auth    | Cost      |
| ----- | ------------- | --------------------------------------------------------------------------- | ------- | --------- |
| 1     | **TMDB**      | Title, overview, genres, cast, crew, poster, backdrop, ratings, collections | API key | Free      |
| 2     | **OMDB**      | IMDb ID, IMDb rating, Rotten Tomatoes score, Metacritic score, awards       | API key | Free tier |
| 3     | **Trakt.tv**  | Community ratings, watchlist sync, watch history, similar titles            | API key | Free      |
| 4     | **Fanart.tv** | HD posters, disc art, clearart, logo PNGs, background art                   | API key | Free      |
| 5     | **TVMaze**    | (fallback for movies that were TV films)                                    | None    | Free      |
| 6     | **Wikidata**  | Canonical linked data, alternate titles, filming locations                  | None    | Free      |

---

### TV Shows — Enrichment Pipeline

| Layer | Service       | What it provides                                                     | Auth    | Cost      |
| ----- | ------------- | -------------------------------------------------------------------- | ------- | --------- |
| 1     | **TMDB**      | Series details, seasons, episodes, cast, network, status, poster     | API key | Free      |
| 2     | **TVMaze**    | Episode-level details, airdate, runtime, guest cast, streaming links | None    | Free      |
| 3     | **TVDB**      | Alternative episode ordering, original air dates, episode artwork    | API key | Free      |
| 4     | **Trakt.tv**  | Ratings, progress tracking, similar shows, watch history sync        | API key | Free      |
| 5     | **OMDB**      | IMDb series rating, awards                                           | API key | Free tier |
| 6     | **Fanart.tv** | Series banners, character art, HD posters, season posters            | API key | Free      |

---

### Books — Enrichment Pipeline

| Layer | Service              | What it provides                                                   | Auth    | Cost      |
| ----- | -------------------- | ------------------------------------------------------------------ | ------- | --------- |
| 1     | **Open Library**     | Author, publisher, year, ISBN, subjects, cover, OLID, edition list | None    | Free      |
| 2     | **Google Books API** | Description, page count, language, categories, preview link, ISBN  | API key | Free tier |
| 3     | **LibraryThing**     | Community tags, reviews, series info, similar books                | API key | Free      |
| 4     | **WorldCat**         | Library holdings, OCLC number, alternate editions                  | API key | Free      |
| 5     | **Wikidata**         | Author biographical data, linked editions, translations            | None    | Free      |

---

### Future Media Types — APIs Ready to Add

| Type               | Primary API                          | Secondary                              | Notes                                      |
| ------------------ | ------------------------------------ | -------------------------------------- | ------------------------------------------ |
| **Podcasts**       | Podcast Index (free, open)           | iTunes Podcast Search (free, no auth)  | RSS-native, fits `media_items` as new type |
| **Games**          | IGDB (free, Twitch auth)             | RAWG (free tier), GiantBomb (free key) | Platforms, genres, multiplayer, ratings    |
| **Audiobooks**     | Open Library + Google Books          | Audible (no public API)                | Same as books + narrator + duration        |
| **Comics / Manga** | ComicVine (free key)                 | MyAnimeList API (free)                 | Issues, arcs, publishers, artists          |
| **Music Videos**   | TMDB (video type) + YouTube Data API | Vevo (no public API)                   | Director, release date                     |

---

### Cross-Media Utilities

| Service                          | Use                                                               | Auth    | Cost      |
| -------------------------------- | ----------------------------------------------------------------- | ------- | --------- |
| **Wikipedia / MediaWiki API**    | Long-form descriptions, biographies, history for any item         | None    | Free      |
| **Wikidata**                     | Structured facts, alternate titles, translations, linked entities | None    | Free      |
| **Fanart.tv**                    | High-quality artwork for music, movies, TV — all in one API       | API key | Free      |
| **OpenSubtitles**                | Subtitle files for video media                                    | API key | Free tier |
| **TMDb's "Find by external ID"** | Translate IMDb ID / TVDB ID → TMDB ID                             | API key | Free      |

---

### Enrichment Architecture

All sources are wrapped behind a common interface so they're hot-swappable and combinable:

```php
interface MetadataSource
{
    public function supports(MediaItem $item): bool;
    public function enrich(MediaItem $item): EnrichmentResult;
    public function priority(): int; // lower = runs first
}
```

`EnrichMediaItemJob` loads all registered sources for the item type, sorts by priority, and runs each. Each source only writes fields it has data for — it never overwrites a field that was set by a higher-priority source or manually by the user (source = 'manual' is always protected).

Sources are registered in `config/metadata_sources.php` — enable/disable any source without code changes. API keys are stored encrypted in the `settings` table and resolved via `SettingsService`, never `.env`.

```php
// config/metadata_sources.php
return [
    'music' => [
        \App\Services\Metadata\FileTagger::class,       // priority 1
        \App\Services\Metadata\AcoustId::class,         // priority 2
        \App\Services\Metadata\MusicBrainz::class,      // priority 3
        \App\Services\Metadata\Discogs::class,          // priority 4
        \App\Services\Metadata\ItunesSearch::class,     // priority 5
        \App\Services\Metadata\Spotify::class,          // priority 6
        \App\Services\Metadata\Lastfm::class,           // priority 7
        \App\Services\Metadata\FanartTv::class,         // priority 8
        \App\Services\Metadata\Genius::class,           // priority 9
    ],
    'movie' => [ ... ],
    'show'  => [ ... ],
    'book'  => [ ... ],
];
```

Lookup strategy per item:
1. Parse any embedded file metadata (ID3 tags, ISBN in filename, etc.)
2. Run audio fingerprint if file exists and tags are missing (music only)
3. Query remaining sources in priority order
4. Flag ambiguous matches for user confirmation (`processing_status = needs_review`)
5. Manual user edits lock a field — no future enrichment run overwrites it

---

## Data Model

### Core tables

**`media_items`** — one row per catalog entry regardless of type
```
id, user_id, type (enum: music | movie | show | book),
title, external_id (TMDB/MusicBrainz/OLID identifier),
cover_image_url, file_path (nullable — only if file was uploaded),
processing_status (pending | processing | complete | failed | needs_review),
user_rating (1–10, nullable), owned (bool), wishlist (bool),
timestamps
```

**`music_metadata`** — joined to media_items where type = music
```
id, media_item_id, artist, album, track_number, disc_number,
release_year, label, genre, bpm (float), key (varchar), scale (major|minor),
duration_ms, isrc, musicbrainz_recording_id
```

**`movie_metadata`** — joined to media_items where type = movie
```
id, media_item_id, director, studio, release_year, runtime_minutes,
tmdb_id, imdb_id, language, country, mpaa_rating, tagline
```

**`show_metadata`** — joined to media_items where type = show
```
id, media_item_id, creator, network, first_air_year, last_air_year,
tmdb_id, season_count, episode_count, status (ongoing|ended|cancelled)
```

**`book_metadata`** — joined to media_items where type = book
```
id, media_item_id, author, publisher, publish_year, isbn_10, isbn_13,
open_library_id, pages, language, series_name, series_position
```

**`media_tags`** — flexible tag store for all types
```
id, media_item_id, type (genre | mood | theme | custom),
value, source (api | file | manual), timestamps
```

**`people`** — reusable across types (directors, actors, authors, musicians)
```
id, name, external_id (TMDB person ID / MusicBrainz artist ID), headshot_url
```

**`media_item_person`** — pivot with role context
```
media_item_id, person_id, role (director | actor | author | artist | producer)
```

**`collections`**
```
id, user_id, name, description, timestamps
```

**`collection_media_item`** — pivot
```
collection_id, media_item_id, sort_order
```

---

## Rule-Based Metadata Enrichment (no AI)

### Music files — what can be extracted deterministically

| Attribute                                | Method                                                         |
| ---------------------------------------- | -------------------------------------------------------------- |
| Title, artist, album, track #            | ID3v2 / Vorbis / MP4 tag parsing — `getID3` PHP library        |
| Duration, sample rate, bit depth, format | `getID3`                                                       |
| BPM                                      | ID3 `TBPM` tag (if present) — no analysis needed if tag exists |
| Key                                      | ID3 `TKEY` tag (if present)                                    |
| Cover art                                | Embedded album art extracted from ID3                          |
| ISRC                                     | ID3 `TSRC` tag → use to look up MusicBrainz                    |

For files missing tags, fall back to **filename parsing** via configurable regex rulesets. Common patterns:
```
"120_Cmaj_Kick_Hard.wav"   → BPM=120, Key=C, Scale=major, instrument=Kick
"Artist - Album - 01 Track.mp3" → artist, album, track number
"[2024] Artist - Title.flac"    → year, artist, title
```
Rules are stored in `config/metadata_rules.php` — fully editable without code changes.

### Movies & Shows

1. User adds title + year (or pastes a TMDB URL)
2. `EnrichMediaItemJob` queries TMDB → populates all metadata, cast, genres, cover
3. Done — no ambiguity for well-known titles

### Books

1. User scans barcode (ISBN) via mobile camera OR enters title
2. `EnrichMediaItemJob` queries Open Library by ISBN or title
3. Writes author, publisher, year, cover, subjects as tags

### Conflict resolution rules

- API data always wins over filename parsing
- Manual user edits always win over API data (source = 'manual' is never overwritten)
- If lookup returns >1 match → set `processing_status = needs_review`, show user a picker

---

## What to Add

### 1. Filament Resources (one per media type)

- `MusicResource`, `MovieResource`, `ShowResource`, `BookResource`
- Shared base: title, cover, rating, owned/wishlist toggle, tags, collections
- Type-specific detail panels with metadata fields
- Global search across all types via Filament's global search

### 2. Add Item Flows

**By search** (primary): type title → live search against TMDB/MusicBrainz/Open Library → pick result → auto-enrich

**By file upload** (music): upload audio files → parse tags → auto-enrich → waveform preview via WaveSurfer.js

**By scan** (books): ISBN barcode input → instant lookup

**Manually**: form-based entry for anything not in any database

### 3. Library Browser UI

- **Dashboard**: counts and recent additions per type, currently playing, reading progress
- **Filters**: genre, year range, rating, owned vs wishlist, person (director/author/artist)
- **Grid / list / table** view toggle
- **Bulk actions**: add to collection, set owned, delete, re-enrich
- **Detail page**: full metadata, cast/crew/authors, similar items, user notes, personal rating

### 4. Music-specific additions

- **Floating audio player** (Livewire persistent component) for uploaded files
- **Waveform preview** via WaveSurfer.js
- **BPM / key filters** in the music library view

### 5. Search

**Laravel Scout + Meilisearch** (free, self-hosted via Docker):
```bash
composer require laravel/scout
php artisan scout:import "App\Models\MediaItem"
```
Indexes: title, tags, people names, notes. Instant cross-type search.

### 6. Metadata Rule Config

`config/metadata_rules.php` — filename parsing patterns, field mappings, genre normalization rules. Users with Admin role can edit via a settings UI without touching code.

---

## What to Change — done

The pivot cleanup listed here is complete: music-industry roles were pruned to
**owner / admin / member**, the customer panel was retired in favour of the
media center, and `UserSchema.md` was rewritten to match.

---

## Build Order

```
Phase 1 — Foundation (self-hosted MVP)                              DONE
  [x] media_items + type-specific metadata tables + media_tags migrations
  [x] MediaItem model + type-specific metadata models + relationships
  [x] Roles simplified to Owner / Admin / Member
  [x] EnrichMediaItemJob (queued, processing_status tracking, retries 3x)
  [x] MetadataSource interface + MetadataPipeline + SettingsService
  [x] Local disk storage driver

Phase 2 — Music Catalog                                    MOSTLY DONE
  [x] MusicResource in Filament
  [x] File upload + getID3 tag parsing (FileTagger)
  [x] Filename rule parser (config/metadata_rules.php)
  [x] BPM/key/genre filters
  [x] AcoustId (fpcalc fingerprinting) + MusicBrainz sources
  [x] Folder importer: php artisan library:import-music <path>
  [ ] WaveSurfer.js waveform + floating audio player (persistent Livewire)

Phase 2.5 — Media Center (not in the original plan)                 DONE
  [x] Streaming-style UI at /app: top nav, hero, poster rails
  [x] Per-type browse pages with filters + full grid
  [x] Full-bleed detail pages
  [x] Cross-type search
  [x] Authenticated audio streaming with range support
  [x] Play tracking (media_plays table)

Phase 3 — Movies & Shows                                            DONE*
  [x] Tmdb source — one API key covers both movies and shows
  [x] MovieResource + ShowResource in Filament
  [x] Title search-and-add (type a title, TMDB fills the rest on save)
  [x] People pivot populated (cast, director, crew)
  [ ] Video direct play via browser (MP4/H.264 — no transcoding)
  * The TMDB sources are written but UNTESTED against the live API — no
    key is configured yet. Add one under Settings → Metadata Sources.

Phase 4 — Books                                                      DONE
  [x] OpenLibrary source (no API key required) — tested against live API
  [x] BookResource in Filament
  [x] Open Library title + ISBN search-and-add
  [x] Barcode/ISBN text input field (autofocused; scanners work as-is)

Phase 4.5 — Remaining metadata sources
  Music:  Discogs, Lastfm, FanartTv, Genius, Deezer
  Movie:  Omdb, Trakt, FanartTv
  Show:   TvMaze, Tvdb, Trakt, FanartTv
  Book:   GoogleBooks, LibraryThing
  Note: registered in config/metadata_sources.php already; the pipeline
        skips any class that doesn't exist yet, so adding one is drop-in.
        FanartTv matters for the UI — it supplies real backdrops, which is
        why the hero currently blurs cover art instead.

Phase 5 — Cross-Catalog & Polish                            MOSTLY DONE
  [x] Dashboard: counts, storage used, genre split, recent activity
  [x] Collections (cross-type playlists / reading lists / watchlists)
  [x] PWA manifest + service worker (installable on phone home screen)
  [~] Bulk actions — re-enrich and mark-owned done; add-to-collection not
  [ ] Global search via Laravel Scout + Meilisearch
        (cross-type search already works via SQL; Scout is for scale)
  [ ] Metadata rule config UI (admin settings page, no code edits needed)

Phase 6 — Import & Export                                   MOSTLY DONE
  [x] CSV export — php artisan library:export <path> [--type=]
  [x] CSV import — php artisan library:import <path>, matched by header name
        so Goodreads/Letterboxd exports work once headers are renamed
  [x] Folder import — php artisan library:import-music <path>
  [ ] Column-mapping UI (would remove the manual header rename)
  [ ] Zip bulk music import (extract + queue each file)
  [ ] Folder watcher for automatic import of new files

Phase 7 — Remote Access & Hardening                         MOSTLY DONE
  [x] Remote access guide — Documentation & Planning/RemoteAccess.md
        (Cloudflare Tunnel, Tailscale, VPS proxy)
  [x] HTTPS-only enforced when APP_ENV=production
  [x] Rate limiting on login (per IP *and* per account), register, streaming
  [x] Trusted proxies, so tunnelled requests report the real client IP
  [ ] API token auth for future mobile app / CLI
  [ ] Two-factor auth

Phase 8 — Optional: Light Transcoding
  [ ] FFmpeg wrapper service for pre-converting HEVC/MKV to H.264 MP4
  [ ] Batch transcode queue with progress tracking
  [ ] Per-file "original" vs "web-optimized" version tracking
  [ ] Note: real-time adaptive transcoding (Plex-style) is out of scope

Phase 9 — Dropped: SaaS / hosted tier
  Multi-tenancy was removed; SoundChex is self-hosted only.
```

---

## Key Architectural Decisions

**Single `media_items` table with type-specific metadata tables** — clean SQL, easy per-type filtering, Filament resources stay simple. Avoids the mess of a fully polymorphic catch-all.

**`MetadataEnricherService` interface** — each media type implements `enrich(MediaItem $item): void`. Adding a new source (Spotify, Discogs, TVDB) or swapping one never touches the job or model.

**Storage driver abstraction** — use Laravel's `Storage` facade throughout, so the storage location is a config change rather than a code change.

**Two path shapes for media files** — uploads land on the storage disk and are stored relative to it; the folder importer registers files where they already live and stores an absolute path, so a large collection isn't duplicated on disk. Everything that touches a file resolves it through `MediaItem::absoluteFilePath()`. Never call `Storage::exists()` on `file_path` directly — it only understands disk-relative paths and will silently report an absolute path as missing.

**SQLite in WAL mode** — cache, sessions, and the queue all share the database, so the default journal (one writer locks everything) produces "database is locked" under concurrent requests. WAL is set in `config/database.php`.

**No AI in core path** — every attribute (genre, cast, author, BPM, key) comes from structured APIs or file tags. AI could be layered on top later as an optional enrichment pass, but it is never a dependency.

**No multi-tenancy** — one server, one library, many accounts. See **Decisions Since This Plan Was Written**.

---

## Future Endeavors (not scoped, but architected for)

| Future feature                              | What makes it feasible from here                                                           |
| ------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Mobile app (iOS/Android)                    | API token auth (Phase 7) + JSON API endpoints expose the same data to a native app         |
| Barcode scanner                             | Capacitor wrapping the web app gives camera access; ISBN lookup already in Phase 4         |
| Social features (reviews, lists, followers) | Accounts and ratings already exist; add a social graph on top                              |
| Recommendations                             | Collaborative filtering on ratings data — no AI needed, matrix factorization               |
| Plugin system                               | `MetadataEnricherService` is already a plugin interface; formalize with a provider pattern |
| Real-time sync across devices               | Laravel Reverb (WebSockets) for live library updates                                       |
| Podcast support                             | RSS-based — fits the `media_items` model as a new type                                     |
| Game catalog                                | IGDB API — same pattern as TMDB                                                            |
| Self-hosted app store / community themes    | Standard Laravel package architecture                                                      |
