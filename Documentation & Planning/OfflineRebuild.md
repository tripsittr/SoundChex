# Offline, rebuilt

Replace the downloads and offline system entirely. It does not work on the
device it was built for, and the reasons are structural rather than a list of
bugs to keep fixing.

Supersedes `NativeDownloads.md` (S-06) and absorbs `NativeOfflineBridge.md`
(S-07). Both are still right about the destination; neither is enough on its
own, and the parts they defer are the parts that are actually broken.

---

## Why replace rather than repair

Sixteen offline issues have been opened and mostly closed — S-21, S-22, S-23,
S-24, S-42, S-53, S-59, S-62, S-63, S-65, S-76, S-79, S-91, S-03, S-06, S-07.
The system is ~3,900 lines across twelve files, it passes 290 browser tests,
and it does not work on the phone. That combination is the finding: the bugs
were real and the fixes were real, and the thing still fails, so the fault is
in the shape rather than the details.

Four structural problems, each measured rather than assumed.

### 1. The tests do not run where the bug is

The `mobile` project **is** real WebKit — `devices['iPhone 13']` carries
`defaultBrowserType: 'webkit'` and the config sets no `browserName` override, so
those specs run on the right engine. That much was already right.

The problem is *which* specs. The mobile project's `testMatch` names twelve
files, and **every spec that tests offline is missing from it**:

| Runs on WebKit (12) | Chromium only (18) |
| --- | --- |
| connection-toast, download-logging, download-queue, downloads-batch, downloads-remove, failover, library-refresh, mobile-touch, phone-dl, phone, player-session, server-transfer | **offline**, **offline-shell**, **offline-probe**, **library-mirror**, **write-queue**, **service-worker**, embedded-shell, access, music, playback, reader, now-playing-sheet, settings, notifications, device-reports, artist-profile, capabilities, upload |

`offline.spec.js` — the one that cuts the network, stores a track and plays it
back — runs only on Chromium, where IndexedDB has a large quota, never evicts,
accepts a `Blob`, and does not close connections on suspend. Same for the
mirror, the write queue and the service worker.

So the four things that fail on the phone are tested exclusively in the engine
that does not fail. The download *queue* is covered on WebKit; the *storage
underneath it* is not.

This is why "it passes nine tests and does nothing when tapped" keeps happening
here, and it is a config line rather than a rewrite — which makes it the
cheapest and most valuable thing in this plan.

### 2. IndexedDB is the wrong store, by a factor of thirty

| | |
| --- | --- |
| Library today | **8,316 music, 11 books, 5 films** |
| Music measured 22 Aug | **34.95 GB across 7,031 tracks** |
| iOS IndexedDB cap | **~1 GB** |

Under 3% of the music fits, before a single film is counted.
`NativeDownloads.md` measured 7.71 GB and the library has grown 4.5× since.
"Download all" cannot succeed and never could — and because nothing checks
first, it fails part way through with a toast rather than declining up front
(Step 5).

Even a *modest* selection hits it: the cap is roughly one film, or an evening's
worth of albums. Per-item downloading does not route around this ceiling, it
just meets it sooner or later depending on what someone picks.

And the bytes are held wrong even below the cap: `localUrl()` reads the whole
file into memory and hands back `URL.createObjectURL(blob)`. An object URL over
a buffer **cannot serve range requests**, so seeking within a downloaded track
or film does not work, and a 2 GB film would have to be resident in RAM — on a
device that will terminate the app long before it gets there.

### 3. Three databases, one problem, fixed three times

`soundchex-downloads`, `soundchex-library` and `soundchex-writes` are three
separate IndexedDB databases, each opened by its own module with its own
connection-recovery code. iOS closes connections on suspend; the fix went into
the first two and S-53 sat open for a fortnight because the third was missed.

That is not a mistake anyone made — it is what three copies of one concern
guarantees over time.

### 4. Downloads die when the app is backgrounded

iOS suspends a webview within seconds of leaving the foreground. A 200 MB album
does not survive answering a text. No web API changes this; it needs
`URLSession` background tasks, which the system continues while the app is
suspended.

---

## What was decided

Answered 9 September 2026, and the plan is scoped to these rather than to a
guess:

**Downloading is per-item and user-driven.** People download what they want
from whichever server library they are connected to. This is not a
"mirror the library" feature, so nothing has to make 34.95 GB fit — it has to
make *a chosen subset* work reliably and refuse honestly when it will not.

**All four media types are downloadable** — music, films, TV and books. That
changes the storage layer's shape more than the count does: a track is
megabyte-scale and a film is gigabyte-scale, so

- a download must **stream to disk**, never buffer the whole item in memory,
- a partial file must be **resumable**, because a 4 GB film will be interrupted,
- playback must **seek** in a local file, which an object URL over a buffer
  cannot do (§2).

Books bring their own wrinkle: a book is a file *plus* its extracted assets —
page text, images, OCR — which today live in `book-assets/` on the server. An
offline book that opens but cannot render a scanned page is a book that does
not work offline, so the download is the file and its assets together.

**"Download all" is gated on free space** — see Step 5, which is a smaller job
than it sounds: `checkSpace()` already totals the download and warns with the
numbers. What it asks is the *browser quota* rather than the disk, and it fails
open when it does not know. A warning that the device will be left with nothing
is acceptable; a silent part-way failure is not.

**All six platforms are in scope**: Android, Linux, Windows 10/11, macOS,
iPadOS and iOS. See *Per platform* below.

## Per platform

Only macOS and iOS have ever been built (`docs/BuildingOnEachPlatform.md`).
The storage interface (Step 1) is what keeps this from becoming six
implementations of everything: each platform supplies a backend, and nothing
above it changes.

| Platform | Storage | Background transfer | State |
|---|---|---|---|
| **iOS / iPadOS** | `Library/Application Support/media/`, excluded from backup | `URLSession` background tasks | Built; the failing case |
| **macOS** | `~/Library/Application Support/SoundChex/media/` | Not needed — no aggressive suspension | Built |
| **Windows 10/11** | `%LOCALAPPDATA%\\SoundChex\\media\\` | Not needed | **Never built** |
| **Linux** | `$XDG_DATA_HOME/soundchex/media/` | Not needed | **Never built** |
| **Android** | `getExternalFilesDir()` — app-private, removed with the app | `WorkManager` + `DownloadManager` | **No Tauri project generated** |

**iPadOS is not a third target.** It runs the iOS build; what differs is
screen size, which the web app already handles. It is named separately because
"it works on iPhone" has never been evidence about the iPad, and the larger
device is where a downloaded film is most likely to be watched.

### What Android needs, specifically

Nothing exists yet — `npm run tauri android init` has never been run, so step 1
below is generating the project rather than writing anything. The sequence is
its own; it slots in after Step 2 of the main plan, once there is a storage
interface for it to implement.

1. **Generate the project and prove a build** (~1 day). Android Studio, the
   NDK, and a signing key. Expect this day to be spent on toolchain rather than
   code, exactly as the iOS bridge was.
2. **The storage backend** (~1 day). `getExternalFilesDir()` rather than
   `getFilesDir()`: app-private either way and removed with the app, but the
   external path is not counted against the app's data quota and is where a
   40 GB library belongs. **Not** `MediaStore` or shared storage — that makes
   the user's media visible to every other app and to the OS's own cleanup.
3. **Serving files back** (~0.5 day). Tauri's asset protocol on Android must be
   proven to honour range requests, the same unknown as iOS. Without it,
   seeking in a downloaded film breaks.
4. **Background transfer** (~1.5 days). `WorkManager` for the queue and
   `DownloadManager` for the transfers, which survive the app being killed.
   Android's constraint is different from iOS's — Doze and App Standby defer
   work rather than suspending the process — so the queue must tolerate a
   download that starts hours later.
5. **Scoped storage and permissions** (~0.5 day). Nothing needs
   `READ_EXTERNAL_STORAGE` if everything stays app-private, which is a reason
   to keep it that way. `POST_NOTIFICATIONS` is needed on 13+ for download
   progress.

**~4.5 days on top of the main plan**, and it can run after Step 2 rather than
blocking it — the interface is what makes that possible.

---

## What the rebuild is

One store, one queue, one sync, behind one interface — with the native path as
the default on every platform that has one, rather than an enhancement bolted
on later.

Downloads are per-item and chosen by the person using it: music, films, TV and
books, from whichever server they are connected to. The job is not to make the
whole library fit — it is to make a chosen subset work, and to refuse honestly
and early when it will not.

```
                    ┌─────────────────────────────┐
                    │      offline/index.js       │  one public API
                    └──────────────┬──────────────┘
                ┌──────────────────┼──────────────────┐
        ┌───────▼──────┐   ┌───────▼──────┐   ┌───────▼──────┐
        │  catalogue   │   │    media     │   │   outbox     │
        │ (the mirror) │   │ (the files)  │   │ (writes made │
        │              │   │              │   │   offline)   │
        └───────┬──────┘   └───────┬──────┘   └───────┬──────┘
                └──────────────────┼──────────────────┘
                        ┌──────────▼──────────┐
                        │   storage backend   │
                        ├─────────────────────┤
                        │ native (iOS/desktop)│  ← files + URLSession
                        │ indexeddb (browser) │  ← unchanged, still works
                        └─────────────────────┘
```

### Step 0 — Make the failure visible first (~1 day)

**Nothing else starts until this does.** Move the offline specs into the
WebKit project. Expect them to fail — that is the point; today's green is the
bug.

- Add `offline`, `offline-shell`, `offline-probe`, `library-mirror`,
  `write-queue` and `service-worker` to the `mobile` project's `testMatch`.
  WebKit is already installed and already running; this is a config change.
- Keep them on Chromium too. A spec that passes on one engine and fails on the
  other is exactly the signal being bought here.
- One test that writes past 1 GB and asserts the failure is *reported* rather
  than swallowed — the current code cannot tell a full disk from a broken write.

Playwright's WebKit is not Safari on iOS and will not reproduce the quota
exactly. It does reproduce the API-level behaviour — `Blob` handling, closed
connections, transaction failures — and it is the difference between a suite
that could catch this class of bug and one that provably cannot.

**Deliverable:** a red suite that describes the real failure.

### Step 1 — One storage interface, two backends (~1.5 days)

A single module owns persistence. Everything else — the download button, the
queue, the player, the shell — talks only to it and never learns which backend
is live.

```js
// offline/storage.js
save(id, stream, { type, bytes })   // streams to disk, never buffers whole
resume(id, from)                    // a 4 GB film will be interrupted
open(id)                            // a URL the player can seek in
remove(id)
list()
space()                             // real free bytes, not browser quota
```

`resume()` and the streaming `save()` are what films and episodes add. A track
tolerated being read into memory and handed back as an object URL; a film does
not, and neither does a book whose reader seeks by page.

Two implementations behind it: `native` (Tauri commands writing real files) and
`indexeddb` (today's path, kept for the browser and desktop web). Chosen once at
startup by capability detection, not by user agent — so a browser tab keeps
working and a phone gets the real thing without either knowing.

This is `NativeDownloads.md` step 3, promoted to first because it is what makes
the rest replaceable.

### Step 2 — Native storage on iOS (~2 days)

From `NativeDownloads.md`, which got this right:

- Files land in `Library/Application Support/media/`, marked
  `isExcludedFromBackup` — not `Caches/` (purged under pressure) and not
  `Documents/` (surfaced in the Files app, invites the OS to move media the app
  is tracking).
- Served back through Tauri's asset protocol, which **honours range requests**,
  so seeking works and a film is never resident in memory.
- Ceiling becomes free space — tens of GB rather than one.

**Prove the asset protocol honours ranges on a device before building on it.**
If it does not, seeking in a downloaded film breaks and that is worse than
today. This is the plan's largest risk and belongs in the first day.

### Step 2a — Real disk numbers (~0.5 day)

**There is no official Tauri plugin for disk space.** The plugin workspace
covers fs, dialog, notification, updater, biometric, os-info and so on;
`os-info` reports platform and architecture, not free bytes. So this is a
command of our own — which the shell already does for `start_service` and
`service_running`, so the pattern is established rather than new.

It needs no crate. `statvfs` is POSIX and present on **macOS, iOS, Linux and
Android**; Windows uses `GetDiskFreeSpaceExW`. Both are one `extern "C"` call.

```rust
#[tauri::command]
fn free_space(path: String) -> Result<u64, String>   // bytes available to us
```

Verified before writing this: against `/` on this Mac it returns **7.1 GB free
of 494.4 GB**, matching `df -k` exactly.

**And the trap, met while proving it.** Two struct layouts returned 1.2 trillion
GB before the third was right: on Darwin `f_bsize`/`f_frsize` are 64-bit while
the block *counts* are 32-bit, and getting that backwards produces a number
that is garbage rather than an error. A wrong reading here is worse than none —
it would refuse every download or approve every one, silently. So the command
returns bytes rather than a struct, and the test asserts against `df` on each
platform rather than against itself.

`sysinfo` is the alternative if the per-platform code is not wanted. It is a
large dependency for one number, and its Android support is the least trodden
part of it — worth reaching for only if the hand-rolled version proves fiddly.

### Step 3 — One database, three stores (~1 day)

Collapse `soundchex-downloads`, `soundchex-library` and `soundchex-writes` into
one database with three object stores and **one** connection manager — open,
recover-on-close, retry — written once.

Migration runs on first launch: copy across, verify, release the old. A version
bump on a database holding gigabytes of blobs is itself risky, which is
precisely why it happens once here rather than per-store forever.

### Step 4 — Background transfer (~2 days)

From `NativeOfflineBridge.md`. Downloads become `URLSession` background tasks
handed to the system, which continues them while the app is suspended and wakes
it on completion. The queue becomes a description of intent that survives the
process, rather than a loop that dies with the webview.

Includes background audio and lock-screen controls, which fail for the same
reason.

### Step 5 — Gate on free space, honestly (~0.5 day remaining)

**A gate already exists**, and reading it first changed what this step is —
two thirds of it were done ahead of the plan (S-111) because they were small
and the failure was live.
`checkSpace()` in `downloads.js` totals what is about to be fetched, compares
it against free space with a 50 MB margin, and `download-button.js` warns with
the numbers and a "Try anyway?" at three call sites. The *shape* asked for is
already the shape shipped.

Three things are wrong with it, and none is the concept:

**1. It asks the wrong source.** `storageEstimate()` returns
`navigator.storage.estimate()` — the browser's quota, not the device's disk.
On iOS that reports a number bounded by the ~1 GB IndexedDB cap, so it is
simultaneously too pessimistic about the device and useless about it. Once
files go native at Step 2 the two stop being related at all. The fix is that
`space()` comes from the storage backend: `statfs` natively, `estimate()` only
in a browser.

**2. It fails open when it does not know.** ~~`known: false` returns
`fits: true`~~ — **fixed ahead of this plan (S-111)**, along with the three
call sites, which each had their own wording and none of which handled a
missing figure. They now share `confirmSpace()`, and a third outcome exists:
*tight* — it fits but leaves little, so warn rather than refuse.

**3. Nothing re-checks while a batch runs.** Still true, and the remaining
work here. A gate that passes at the start and then fills the disk at item 400
has moved the failure rather than prevented it — something else on the device
is writing too. A batch that runs out must **pause** and say so, keeping what
it already has, rather than failing every remaining item in turn (the S-21
shape). This needs the queue, so it belongs after Step 4.

The behaviour, once the number is real:

| Situation | What happens |
|---|---|
| Comfortably fits | Starts, no interruption |
| Fits, but leaves under ~10% or ~5 GB free | **Warns and asks** — names the download size, the free space, and what would be left. Proceeds if confirmed |
| Does not fit | **Refused before it starts**, naming the shortfall: "38.2 GB needed, 12.4 GB free" |
| Free space unknown | Says so, and asks rather than assuming |

The middle row is what was asked for: a device left with nothing is the user's
call, not the app's to prevent — but it has to be a *choice* made with the
numbers in front of them, rather than something discovered when the phone stops
working.

**The same gate covers a single large item.** One 4 GB film is likelier to fill
a device than a hundred tracks, and `checkSpace()` is already called on the
single-item path — it just has to be believed.

**Reporting, throughout:** every refusal and failure logs what was attempted,
what came back, and the item id (AGENTS.md rule 7). "Checking…" sitting on
screen with no outcome is the reported symptom today.

### Step 6 — Delete the old system (~0.5 day)

`useLocalSource()` in `download-button.js` is already dead (S-38). Once the
interface is in place, the rest of the old paths go with it. A rebuild that
leaves both alive is two systems, and the next bug will be in whichever one is
not being read.

**Total: ~9 days**, plus **~4.5 days for Android** once the interface exists.
Step 0 is a day and changes nothing a user sees; it is also the only step that
makes the rest verifiable.

---

## What stays

Not everything is wrong, and a rewrite that discards these re-earns their bugs:

- **The mirror as a complete catalogue rather than a cache.** 0.61 MB of JSON,
  94 KB gzipped, holding all of it, so "which items do I have?" is answerable
  offline. This is the single best decision in the current system.
- **The offline shell shipped inside the app** (S-62). A cache is an
  optimisation; a bundle is a guarantee, and a fresh install has never
  populated a cache.
- **Address racing and failover.** 375 lines that measure every route and take
  the fastest, and it works.
- **The write queue's semantics.** Replaying writes made offline is right; it
  just should not own a third database to do it.

## Risks

- **Tauri's iOS plugin path is younger than its desktop one.** Expect the first
  day to prove the bridge on a device rather than build features.
- **Migration touches gigabytes of user data.** It moves files people chose to
  keep; verify before releasing the old copy, and never delete on an unverified
  write.
- **WebKit in Playwright is not Safari on iOS.** Step 0 raises the floor; it
  does not remove the need to test on the phone. The device stays the final
  word.
- **Android is unproven end to end.** No project has been generated, so the
  first day is toolchain rather than features, and the asset protocol's range
  support there is the same unknown as on iOS. Budget for it going badly.
- **Free space is a moving number.** Something else on the device writes while
  a batch runs, so the Step 5 gate re-checks rather than trusting the reading
  it started with. A gate that only checks up front moves the failure to item
  400 rather than preventing it.
- **Six platforms is five more than have ever been tested.** The interface is
  what keeps that from being five rewrites, but each still needs one real build
  and one real download before it can be claimed.

## Not started
