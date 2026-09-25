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
identified, and nine sources are asked in turn — TMDB (films and shows),
MusicBrainz, AcoustID, Deezer, iTunes, Spotify, Open Library and the files' own
tags. Subtitles come separately, from OpenSubtitles. Every run
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

**Playing to whatever is in the room.** Adaptive HLS for anything that will not
play directly, AirPlay from the apps, and a DLNA/UPnP server so devices with no
SoundChex app at all — older smart TVs, consoles, network receivers — can browse
and play the library natively. Off by default; turned on from Admin → Network.

**Shuffle that has listened to you.** Smart shuffle weights the queue by what
this profile actually plays, rather than dealing the same uniform random
sequence every time.

**Duplicates found and merged.** Two passes: byte-identical files of any type,
then a music-content match on ISRC, MusicBrainz id, AcoustID fingerprint and
fuzzy title. The second pass only ever proposes — merging is yours to confirm.

**Plugins.** A plugin is a folder with a manifest and a class: it can hook
events, filter what the pipeline produces, add cover sources and notification
targets, and ship its own admin page. Installed from a catalog at runtime, so
adding one does not mean rebuilding the server. See
**[docs/plugins/](docs/plugins/)** and the worked examples in
`plugins/examples/`.

---

## How it is put together

|              |                                                        |
| ------------ | ------------------------------------------------------ |
| **Server**   | Laravel 13, PHP 8.3+, SQLite                           |
| **Admin**    | Filament 5                                             |
| **Frontend** | Blade, Tailwind v4, Alpine, vanilla ES modules         |
| **Apps**     | Tauri 2 — macOS, Windows, Linux                        |
| **iOS**      | Native Swift/SwiftUI, in [SoundChexiOS][ios-repo]      |
| **Media**    | FFmpeg for transcoding, Chromaprint for fingerprinting |

SQLite rather than MySQL on purpose: one file to back up, no service to keep
running, and a library of a few thousand items never gets near its limits.

The web app is served rather than compiled into the native apps, so a deploy
reaches every device on the next page load. Only the connect screen and the
offline shell live inside the binary, and those need a reinstall.

---

## Getting it running

Requires PHP 8.3+, Node 22+, Composer, and FFmpeg on the path.

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
npm run tauri build                    # desktop
npm run build:server                   # the host app, with service controls
npm run reinstall:server               # rebuild + reinstall /Applications/SoundChex Server.app
```

For a faster reinstall when the app is already built:

```bash
npm run reinstall:server -- --skip-build
```

iOS is not built here. It is a native Swift app in its own repository,
[SoundChexiOS][ios-repo], built with XcodeGen and Xcode against this server's
JSON API. It needs a paid Apple developer account for a profile that lasts a
year; a free one expires in seven days.

Tauri builds for the platform it runs on — a Mac produces the `.dmg`, not the
`.msi`. **[docs/BuildingOnEachPlatform.md](docs/BuildingOnEachPlatform.md)**
covers what each target needs, and is honest about how far each has actually
got: macOS is built and run daily; Windows and Linux compile in CI on every
push to `main` but no one has yet *run* either binary on its own platform;
Android has no Tauri project generated at all.

The `.dmg` build scripts run under `CI=true` (baked into `tauri:build` and
`build:server`). Without it, Tauri's `bundle_dmg.sh` runs AppleScript to style
the Finder window and **hangs** whenever there is no interactive GUI session
(S-74) — no error, just a stall and no `.dmg`. `CI=true` skips the styling and
produces a plain but valid `.dmg`.

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
php artisan test     # PHP
npx vitest run       # JS unit
npx playwright test  # browser, across desktop, mobile and shell
```

Counts are deliberately not written down here: they were wrong by several
hundred within a few months of being typed. Run the suite for the number.

The browser tests run against an isolated database and generated media in a
scratch directory. They never touch a real library — the bootstrap refuses to
run if its paths are not scratch paths.

---

## Documentation

- **[AGENTS.md](AGENTS.md)** — architecture, conventions and the rules that
  matter, including the two about never committing media and verifying before
  destroying.
- **The admin Tracker** — every feature, fix and bug for *all* SoundChex repos
  is tracked in the landing site's admin panel ([SoundChexWebsite][web-repo] →
  `/admin` → Tracker), not in a file here. Add one with `php artisan
  track:issue` and move it with `php artisan track:move`, both run from that
  repo. `Documentation & Planning/Issues.md` is now only a pointer to it.
- **[changelog/](changelog/)** — one entry per pull request, saying what
  changed and what is still broken.
- **[docs/Versioning.md](docs/Versioning.md)** — SemVer with a named minor.
  Film terms here, music terms on the phone, and why every build carries the
  commit it came from.
- **[Documentation & Planning/Status.md](Documentation%20&%20Planning/Status.md)**
  — what exists, what does not, and what is next.
- **[docs/WorkingOnSoundChex.md](docs/WorkingOnSoundChex.md)** — what this
  project has taught, mostly the hard way.

---

## Licence

SoundChex is **dual-licensed** — see [LICENSING.md](LICENSING.md):

- **GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)** — the
  open-source licence, free of charge (full text in [LICENSE](LICENSE)); or
- a **commercial licence** for those who cannot or do not wish to comply with the
  AGPLv3 (e.g. embedding it in a closed-source product, or hosting a service
  without publishing modifications). Contact **licensing@soundchex.app**.

Most people use SoundChex under the AGPLv3 and owe nothing.

SoundChex is free and open source. You may run, study, modify and share it. The
AGPL adds one thing over the ordinary GPL: **if you run a modified version and
let other people use it over a network, you must offer those users the complete
corresponding source** — modifications included. So improvements to SoundChex
stay open, even when it is offered as a hosted service.

The application is the open-source part; any paid **SoundChex network/relay
service** is a separate product built around it, and does not change these terms
for the app itself.

**Getting the source of a running instance.** Every build is stamped with its
version and the commit it was built from, and the *About* page in the app links
the source pinned to that commit — not to `main`, which is a different tree by
the time you read this. A build made from a modified working tree says so, and
its operator should point `SOUNDCHEX_REPO_URL` at their own repository. If you
modify SoundChex and host it for others, you must make your modified source
available to those users in the same way (a link in the app or on your site
satisfies AGPL §13).

Your media is not covered by any of this. SoundChex is a library for files you
already have; it neither acquires them nor helps you to.

---

## AI Disclaimer

SoundChex is initially built by Blaze Claeson (@tripsittr) and Claude Code.
Claude Code assists in the following:
- Test writing
- GitHub management (PRs, commits, merging, and general housekeeping tasks)
- Code writing for iOS (in the separate SoundChexiOS repository) and the other desktop platforms
- Code writing for Tauri, Rust, and cross-platform capabilities
- Planning, document drafting, and maintaining the landing site roadmap and tracker on the backend

All code is reviewed and tested before it is merged, and the macOS build is in daily use.

This is a passion project of mine. I have attempted to build out a media server for a long time, but I was never able to get the features and support I wanted. Having learned Laravel, PHP, and various languages and frameworks around it, I decided why not have Claude build the bridge I was missing using Tauri, porting the web app to other platforms.

Thank you for checking out my project. I do use this myself every day and hope you will too. Feel free to contribute, give it a star, or sponsor me to help keep this project going!

[ios-repo]: https://github.com/tripsittr/SoundChexiOS
[web-repo]: https://github.com/tripsittr/SoundChexWebsite
