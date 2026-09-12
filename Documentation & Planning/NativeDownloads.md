# Native downloads

> **Superseded — historical reference.** Its storage design became Step 1–2 of the rebuild. The live plan is
> [OfflineRebuild.md](OfflineRebuild.md), with a tickable breakdown in
> [OfflineRebuildTasks.md](OfflineRebuildTasks.md). Kept for the reasoning
> behind the decisions, not as work to execute.

Store downloaded media in the app's own storage on the device, rather than in
IndexedDB, so the limit is free space rather than a browser quota.

## Why

Measured against the real library:

| | |
| --- | --- |
| Music | **7.71 GB** across 1,438 files |
| Films | 1.77 GB (one file) |
| Books | 0.03 GB |
| **iOS IndexedDB cap** | **~1 GB** |

**About 13% of the music fits, and a single film does not fit at all.** Safari
also evicts that storage from apps that have not been opened recently, which is
precisely when someone reaches for music they downloaded for a flight.

Native storage moves the ceiling to free space on the device — tens of
gigabytes — and takes the files out of reach of eviction entirely.

## What exists now

Downloads work: `resources/js/downloads.js` streams a file, reports progress,
stores it and plays it back from an object URL. The recent WebKit fix — storing
`{ buffer, type }` rather than a `Blob`, which Safari refuses — means it works
on the phone at all.

What it cannot do is hold more than a gigabyte, or survive eviction.

## Plan

### 1. A storage bridge in the shell (~1 day)

Two Tauri commands, `save_media` and `delete_media`, writing into
`Library/Application Support/media/`. Everything else stays where it is: the
download button, the progress indicator, the queue and the offline shell all
keep working, and only the final write changes.

That location is where Netflix and Spotify put downloaded media, and each
alternative is wrong for a reason:

- **`Caches/`** is purged by iOS under storage pressure — the same eviction
  problem in a different place.
- **`Documents/`** is surfaced in the Files app if the app declares it, which
  invites the OS and the user to move or delete media the app is tracking.
- **`Application Support/`** is private, survives backup, and is removed with
  the app. It is what a downloads feature is expected to do.

Marked `isExcludedFromBackup`, or a 40GB library quietly fills someone's
iCloud. The two apps above do the same.

### 2. Serve the files back (~1 day)

A saved file has to reach an `<audio>` or `<video>` element. Tauri's asset
protocol serves a local file to the webview, which is what makes playback work
without loading a gigabyte into memory — and what makes **seeking** work, since
the protocol honours range requests and an object URL over a buffer does not.

### 3. One storage interface, two backends (~half a day)

`downloads.js` grows a backend it picks at runtime: native where the bridge
exists, IndexedDB everywhere else. The browser and the desktop app keep working
unchanged, and no calling code learns which is in use.

### 4. Migrate what is already downloaded (~half a day)

Anything already in IndexedDB is copied across on first launch and the old copy
released. Without this the cap is still in force for existing downloads, and
the same file could be held twice.

### 5. Storage that tells the truth (~half a day)

The downloads page currently reports the browser quota, which stops being the
real limit. It should report free space on the device, what the library is
using, and refuse a download that will not fit — with a reason, before it
starts, rather than a failure part way through.

### 6. Tests (~1 day)

- The bridge writes, reads back and deletes, on WebKit.
- A file survives a restart, which is the whole point.
- Playback and **seeking** work from a saved file — an object URL over a
  buffer cannot seek, so this must be asserted rather than assumed.
- Migration moves an existing IndexedDB download and does not duplicate it.
- The IndexedDB path still works where no bridge exists, so the browser build
  is not quietly broken.

**Total: ~4–5 days.**

## Deliberately not doing

- **The Files app.** Media in a user-visible folder invites the OS and other
  apps to move or delete it, and the app would have to handle files vanishing
  underneath it. App storage is private, survives backup and is deleted with
  the app, which is the behaviour people expect from a downloads feature.
- **Downloading on Android or desktop first.** Desktop has no meaningful cap
  and the browser build cannot use native storage at all, so the work is only
  worth doing where the limit actually bites.
- **Background downloading.** iOS suspends a webview aggressively, so a large
  download needs a native URLSession task to survive the app being backgrounded.
  Worth doing, but it is a second project — this one is about where the bytes
  land, not when.

## Risks

- **Tauri's iOS plugin support is younger than its desktop support.** The file
  system and asset protocol APIs are stable, but the iOS path is less trodden;
  expect the first day to be spent proving the bridge works on a device rather
  than writing features.
- **Range requests.** If the asset protocol does not honour them on iOS,
  seeking within a downloaded film breaks — which would be a worse experience
  than today. Worth testing before the rest is built on it.
- **iCloud backup.** Anything under Application Support is backed up unless
  marked `isExcludedFromBackup`. Missing that would quietly fill someone's
  iCloud with tens of gigabytes of media they already own.

## Not started
