# SoundChex

A self-hosted media library for music, films, television and books — one
catalogue, one player, on every device you own.

It runs on your own machine. The files stay yours, nothing is uploaded
anywhere, and the only thing between you and your library is a network you
control.

---

## What it does

**One catalogue across four media types.** Music, films, TV and books share a
schema, a search box and a player. A song, an episode and a chapter are all
things you were part-way through, and the app treats them that way.

**Metadata that fills itself in.** Watch folders are scanned, files are
identified, and nine sources are asked in turn — TMDB, MusicBrainz, AcoustID,
Open Library, iTunes, Spotify, OpenSubtitles and the files' own tags. Every run
snapshots what it replaced, so a provider revising its own record is
recoverable rather than merely regrettable.

**Files organised the way other tools expect.** `Artist/Album/`,
`Title (Year)/`, `Series/Season 01/` — the conventions Plex, Jellyfin and Emby
already read, so the library stays legible to anything else you point at it.

**Reading and watching, not just listening.** EPUB, PDF and CBZ with
highlights, private notes and OCR of scanned pages so the text is selectable.
Video with hardware transcoding, caption tracks, skip markers and a full-screen
player.

**Search that reaches inside things.** One query across titles, metadata, cast,
film dialogue and the text of books. A dialogue hit jumps to the moment it is
spoken; a book hit opens at the page.

**Profiles.** Per-person history, resume points and watchlists, with a kids
mode that caps ratings across browsing, search and direct links.

**Offline.** The whole catalogue mirrors to the device as JSON — a fraction of
a megabyte gzipped — so browsing, search and playback of downloaded files work
with no network at all. Downloads survive the app closing and resume when it
comes back.

---

## How it is put together

| | |
| --- | --- |
| **Server** | Laravel 13, PHP 8.4, SQLite |
| **Admin** | Filament 5 |
| **Frontend** | Blade, Tailwind v4, Alpine, vanilla ES modules |
| **Apps** | Tauri 2 — macOS, Windows, Linux, iOS |
| **Media** | FFmpeg for transcoding, Chromaprint for fingerprinting |

SQLite rather than MySQL on purpose: one file to back up, no service to keep
running, and a library of a few thousand items never gets near its limits.

The web app is served rather than compiled into the native apps, so a deploy
reaches every device on the next page load. Only the connect screen and the
offline shell live inside the binary, and those need a reinstall.

---

## Getting it running

Requires PHP 8.4+, Node 22+, Composer, and FFmpeg on the path.

```bash
git clone https://github.com/tripsittr/SoundChex.git
cd SoundChex

composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link

npm run build
php artisan serve
```

`storage:link` is easy to skip and fails silently: without it every avatar and
artist image returns 404 with nothing explaining why.

**On Windows, PHP needs a certificate bundle.** It ships without one, so every
outbound HTTPS request fails with `cURL error 60: unable to get local issuer
certificate` — metadata lookups, artwork, subtitles and server transfers alike.
Download [cacert.pem](https://curl.se/ca/cacert.pem) and point `php.ini` at it:

```ini
curl.cainfo = "C:\path\to\cacert.pem"
openssl.cafile = "C:\path\to\cacert.pem"
```

macOS and Linux already have a system bundle and need nothing.
[docs/SettingUpOnWindows.md](docs/SettingUpOnWindows.md) covers the rest.

Then point it at your media: **Admin → Library settings → watch folders**, and
run a scan.

```bash
php artisan library:scan
```

Metadata sources that need a key — TMDB, AcoustID, Spotify, OpenSubtitles — are
entered under **Admin → Metadata sources**. The rest work without one. A source
with no key skips itself and says so in the log rather than silently returning
nothing.

### The background workers

```bash
php artisan queue:work        # enrichment, transcoding, downloads
php artisan schedule:work     # scanning, backups, pruning
```

Both ship as launchd plists in `Documentation & Planning/` for running them at
login on macOS.

---

## The native apps

```bash
npm run tauri ios build -- --export-method debugging
npm run tauri build                    # desktop
npm run build:server                   # the host app, with service controls
```

iOS needs a paid Apple developer account for a profile that lasts a year; a
free one expires in seven days.

Tauri builds for the platform it runs on — a Mac produces the `.dmg`, not the
`.msi`. **[docs/BuildingOnEachPlatform.md](docs/BuildingOnEachPlatform.md)**
covers what each target needs, and is honest about which have actually been
built: macOS and iOS have, Windows, Linux and Android have not.

Setting up on Windows has its own step-by-step, with the silent failures called
out: **[docs/SettingUpOnWindows.md](docs/SettingUpOnWindows.md)**.

---

## Reaching it from elsewhere

The app races every address it knows and takes the fastest that answers,
re-checking every thirty seconds and whenever the device wakes. A relay is
ranked last however well it measures — it works from anywhere, and it is
twenty times slower than a direct route.

`Documentation & Planning/RemoteAccess.md` covers the options and what each
one measured.

---

## Tests

```bash
php artisan test     # 289 PHP
npx vitest run       # 87 unit
npx playwright test  # 282 browser, across desktop, mobile and shell
```

The browser tests run against an isolated database and generated media in a
scratch directory. They never touch a real library — the bootstrap refuses to
run if its paths are not scratch paths.

---

## Documentation

- **[AGENTS.md](AGENTS.md)** — architecture, conventions and the rules that
  matter, including the two about never committing media and verifying before
  destroying.
- **[Documentation & Planning/Issues.md](Documentation%20&%20Planning/Issues.md)**
  — every feature, fix and bug being tracked, and where each got to.
- **[Documentation & Planning/Status.md](Documentation%20&%20Planning/Status.md)**
  — what exists, what does not, and what is next.
- **[docs/WorkingOnSoundChex.md](docs/WorkingOnSoundChex.md)** — what this
  project has taught, mostly the hard way.

---

## Licence

**GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).** The full
text is in [LICENSE](LICENSE).

SoundChex is free and open source. You may run, study, modify and share it. The
AGPL adds one thing over the ordinary GPL: **if you run a modified version and
let other people use it over a network, you must offer those users the complete
corresponding source** — modifications included. So improvements to SoundChex
stay open, even when it is offered as a hosted service.

The application is the open-source part; any paid **SoundChex network/relay
service** is a separate product built around it, and does not change these terms
for the app itself.

**Getting the source of a running instance.** Every SoundChex server exposes the
complete corresponding source of the exact build it is running — see the *About*
page in the app, which links to this repository at the running version. If you
modify SoundChex and host it for others, you must make your modified source
available to those users in the same way (a link in the app or on your site
satisfies AGPL §13).

Your media is not covered by any of this. SoundChex is a library for files you
already have; it neither acquires them nor helps you to.
