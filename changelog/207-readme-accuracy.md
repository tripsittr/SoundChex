# README says what the code actually does

An audit of all three repos' READMEs against the code. This is the App repo's
share; the iOS and Website repos have their own.

## Wrong, now fixed

- **PHP 8.4+ was stated; `composer.json` requires `^8.3`.** Someone on 8.3 read
  that and concluded they had to upgrade. 8.3 is the house baseline — the
  Website repo requires the same.
- **iOS was listed as a Tauri target** in the stack table and in the build
  commands. iOS went native months ago and lives in `SoundChexiOS`. The README
  never mentioned that repo at all; it does now.
- **"Windows, Linux and Android have not [been built]"** — contradicted by the
  very document the sentence cites. Windows and Linux compile in CI on every
  push; what has not happened is anyone *running* either binary on its own
  platform. Only Android has no project at all.
- **Test counts were wrong by hundreds** (289 PHP against 989 methods; 282
  browser against 229). Hard-coded counts rot. They are gone, with a line
  saying why.
- **Nine metadata sources were claimed and eight were named** — and one of the
  eight, OpenSubtitles, is a subtitle service rather than a metadata source,
  while Deezer is a real source that was missing.
- **`Documentation & Planning/Issues.md` was described as the tracker.** It has
  been a pointer for some time; the tracker is the landing site's admin panel.
  `Status.md` carried the same stale claim and is fixed too.
- **The AGPL §13 source-offer claim overstated what ships.** It said the About
  page links this repository at the running version. There is no About page,
  the only source links are bare, and with `tauri.conf.json` at `0.1.0` and no
  git tags there is nothing for a version link to point at. Filed as S-401 and
  the claim softened to what is true — a licence claim should not be aspirational.

## Missing, now documented

The README described none of: the plugin platform, DLNA/UPnP output, HLS
adaptive streaming, AirPlay, smart shuffle, or duplicate detection. Six shipped
features, one of them an extensibility platform with a contract API, an
installer and four worked examples.

Also added: the admin tracker workflow, and a link to `changelog/`.

## The AI Disclaimer

Kept as written, with three corrections: "initially build" → "built"; the claim
of Android code removed (there is none in this project, and iOS code is in
another repo); and "put to use in the real world as soon as it is pushed"
narrowed to what is supportable, given nobody has run the Windows or Linux
builds.

## Not a finding

`npm run build` was already correct and stays that way — bare `vite build`
would skip the offline-shell embed and the Tauri bridge.
