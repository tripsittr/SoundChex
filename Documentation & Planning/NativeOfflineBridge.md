# Native offline bridge

> **Superseded — historical reference.** Its background-transfer design became Step 4 of the rebuild. The live plan is
> [OfflineRebuild.md](OfflineRebuild.md), with a tickable breakdown in
> [OfflineRebuildTasks.md](OfflineRebuildTasks.md). Kept for the reasoning
> behind the decisions, not as work to execute.

Keep the Tauri UI. Move only the parts the web platform genuinely cannot do —
storage, background transfer and background audio — into a native plugin behind
a narrow interface.

## Why this and not a Swift app

The question that prompted this was whether to write a native Swift iOS app
against the JSON API instead. The honest comparison:

**A Swift app would give us** a real filesystem, `URLSession` background
downloads that continue while the app is closed, first-class background audio
and lock-screen controls, and no IndexedDB quirks. Those are real, and three of
them are exactly what is failing today.

**It would cost** a second implementation of every screen already built and
tested — browse, album, artist, player, reader, downloads, settings — plus every
future feature built twice or allowed to diverge. It also covers only iOS and
tvOS: Windows and Linux would still need the Tauri shell, so it adds a codebase
rather than replacing one. The signing constraints are identical, because they
are Apple's rather than Tauri's.

Most of what has gone wrong recently was application logic — a service worker
that never evicted, unbound download buttons, a subnav removed by a pre-render,
a library sync shipping six times more bytes than needed. A rewrite inherits
every one of those and loses the tests that now cover them.

So: native where native is genuinely required, web everywhere else.

## What is already scoped elsewhere

`NativeDownloads.md` covers **where the bytes land** — a storage bridge writing
into `Library/Application Support/media/`, served back through the asset
protocol so seeking works. That plan stands and is a prerequisite for this one.
It explicitly defers background downloading as "a second project". This is that
project, plus the two other things a webview cannot do.

Read that plan first. This one assumes its storage bridge exists.

## What the web platform actually cannot do

Each of these is a limit, not a bug to be fixed in JavaScript.

**Downloads stop when the app is backgrounded.** iOS suspends a webview within
seconds of the app leaving the foreground. A 200 MB album download does not
survive answering a text message, and there is no web API that changes this.
`URLSession` background tasks are handed to the system, which continues them
while the app is suspended and wakes it on completion.

**Audio stops at the same boundary, eventually.** A `<audio>` element in a
webview keeps playing when backgrounded only while the OS tolerates it. Native
audio with a configured `AVAudioSession` category is the supported path, and it
is also what puts real lock-screen and Control Centre controls in place.

**Storage is capped and evictable.** Covered by `NativeDownloads.md`: ~1 GB, and
purged from apps not opened recently.

Everything else the app does — rendering, navigation, the queue, the mirror, the
write queue — works in the webview and should stay there.

## Plan

Each step leaves the app working. Steps 1–2 depend on `NativeDownloads.md`
having landed; 3 onward do not.

### 1. Prove the plugin path on a device (~1 day)

Before designing anything, establish that a Tauri iOS plugin can call Swift and
return a result to the webview. Tauri's iOS plugin support is younger than its
desktop support, and this is the assumption everything else rests on.

Deliverable: a `ping` command implemented in Swift, called from JavaScript,
returning a value, running on the phone. If this does not work cleanly, the
plan stops here and native becomes a genuine argument rather than a hypothesis.

### 2. Background downloads through URLSession (~2 days)

Replace the fetch-and-store path with a native download task for the iOS build
only.

- `start_download(id, url, destination)` hands the transfer to a background
  `URLSession`, which continues while the app is suspended.
- Progress is reported back to the webview by event, so the existing button
  states and the queue keep working unchanged.
- Completion writes into the storage location `NativeDownloads.md` establishes
  and emits a finished event.
- The existing serial queue stays in JavaScript. It is the right place for it:
  the ordering rule is a product decision, not a platform one.

The web path remains for desktop and browser. This is a backend behind the same
interface, exactly as the storage bridge is.

### 3. Background audio (~1 day)

Configure an `AVAudioSession` with the playback category so audio continues when
the app is backgrounded or the screen locks, and populate `MPNowPlayingInfoCenter`
so the lock screen shows the track and its controls work.

The player itself stays in JavaScript — the queue, shuffle, repeat and the
session persistence just built. Only the audio session configuration and the
remote-control targets are native, and they call back into the existing player
rather than reimplementing it.

### 4. One interface, three backends (~half a day)

`downloads.js` already needs a backend switch for `NativeDownloads.md`. This
extends it: native-with-background on iOS, native-storage on desktop, IndexedDB
in a browser. Calling code learns none of it.

### 5. Tests (~1.5 days)

The parts that can be tested without a device, tested there; the parts that
cannot, verified on the phone and written down.

- The backend switch picks correctly per platform, and the IndexedDB path still
  works where no bridge exists — so the browser build cannot break silently.
- Progress events drive the same button states the fetch path does.
- A download interrupted by backgrounding completes. This one is manual: it
  needs a real device and a real suspension.
- Audio survives backgrounding, and the lock screen shows the right track.
- Seeking works in a downloaded file, per `NativeDownloads.md`.

**Total: ~6 days**, on top of `NativeDownloads.md`'s ~4–5.

## Deliberately not doing

- **Rewriting the UI in Swift.** The whole point is to keep one implementation
  of every screen. If the plugin path in step 1 proves unworkable, that
  conclusion is worth having — but it is not the starting assumption.
- **Android.** Same shape of problem, different API, and no device in play. The
  interface from step 4 leaves room for it without committing now.
- **A native player.** The queue, shuffle, repeat and session persistence are
  built and tested in JavaScript. Native audio session configuration is a
  wrapper around that, not a replacement for it.
- **Native networking for anything but downloads.** The API calls are small and
  fast; only the large transfers need to outlive the foreground.

## Risks

- **Tauri iOS plugin maturity.** Step 1 exists precisely to find this out early
  rather than three days in.
- **Two transfer paths to keep honest.** A bug fixed in one backend and not the
  other is the obvious failure mode. The shared interface and the backend-switch
  tests are what guard against it.
- **Background task limits.** iOS grants background transfers generously but not
  infinitely; a very large download may still be suspended. Worth measuring with
  a real film rather than assuming.
- **This does not fix offline mode by itself.** The current offline problems are
  not yet diagnosed, and if they are application logic then native storage will
  not help. That diagnosis should come first — see below.

## Before starting

**Resolved, 21 August 2026.** The offline failures were application logic, not
platform limits, and every one of them has been fixed: the offline shell
pre-rendered all ~1,350 items with artwork and flooded the network, a top-level
`return` killed the connect screen outright, and the downloads view read the
wrong IndexedDB store so it reported "nothing saved" while holding downloads.

That answers the question this section was waiting on. None of those were the
storage cap or webview suspension, so this plan was never the fix for them — and
equally, none of them being the cause means the three things listed under *What
the web platform actually cannot do* are still genuinely unaddressed. The plan
stands on its own merits rather than as a remedy for a bug that turned out to be
elsewhere.

Worth re-measuring the storage cap on device before step 1, so the case for the
work rests on a current number rather than the one that motivated it.

## Not started
