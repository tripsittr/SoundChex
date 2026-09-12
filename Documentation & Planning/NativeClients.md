# Native clients, or not

> **Superseded — historical reference.** Its served-vs-bundled decision is settled: served, kept. The live plan is
> [OfflineRebuild.md](OfflineRebuild.md), with a tickable breakdown in
> [OfflineRebuildTasks.md](OfflineRebuildTasks.md). Kept for the reasoning
> behind the decisions, not as work to execute.

Whether to build iOS and Android apps against the API and keep the web app for
desktop and browsers.

Raised on 22 August 2026, after long press, link previews and background
downloads each turned out to be the platform fighting the web app rather than a
bug in it.

## The honest starting point

Three of this session's bugs were the web platform losing to iOS:

- A long press highlighted text instead of opening the menu, because iOS
  claims that gesture for text selection.
- Holding a link offered "Open in Safari", because iOS claims that gesture for
  link previews.
- IndexedDB was closed out from under the page, and every transaction after it
  failed until the app restarted.

All three are now fixed, and none of the fixes were hard. But they are the
same *kind* of problem, and the list of them does not end — it is the tax on
running a web app in a native shell.

Against that, two more that no amount of CSS will fix: downloads stop when the
app is backgrounded, and IndexedDB caps storage at about a gigabyte against a
7.71 GB music library.

## For a native client

**Gestures are the platform's, not a simulation of it.** Long press, swipe to
delete, drag to reorder, peek and pop, haptics — all of it native, correct on
the first try, and correct again when the OS changes how they feel. No
`-webkit-touch-callout` rules to discover one symptom at a time.

**Background work is a solved problem.** `URLSession` background downloads
continue when the app is closed, survive a reboot, and are resumed by the OS.
That is the whole of `NativeOfflineBridge.md` — six days of work — replaced by
an API that already exists.

**Storage is free space, not a quota.** `NativeDownloads.md` exists entirely to
escape IndexedDB's ~1 GB cap. A native app writes to its own container and the
limit is the disk.

**Audio that behaves.** `AVAudioSession` gives lock-screen controls, CarPlay,
AirPlay, correct interruption handling and background playback. The web app
approximates some of this through the Media Session API and loses playback when
the webview is suspended.

**Offline is the default posture.** A native app holds its catalogue in SQLite
or Core Data and renders from it. No mirror to keep in step with a server, no
service worker deciding whether it is allowed to answer, and no ~900ms round
trip standing between a tap and a screen — which is the actual complaint that
started all of this.

**Push notifications work properly**, including while the app is closed, which
is the case that matters.

## Against a native client

**It is two more codebases, not one.** Swift for iOS, Kotlin for Android, and
the web app continues for desktop and browsers. Every feature is then built
three times and every bug fixed three times — and this project has one person
on it.

**The API is not ready for it.** The web app is server-rendered Blade. There is
an `/api/v1` surface but the app does not eat its own API for browsing, and a
native client would need endpoints for everything: browsing, search, playlists,
profiles, progress, ratings, the reader, credits. That is real work before a
single screen exists.

**The reader is a large body of work.** EPUB, PDF and CBZ rendering,
highlights, notes, OCR text overlay with word boxes, full-text search. Months
of it, and it would be rebuilt from nothing on each platform.

**Distribution gets harder, not easier.** Sideloading is already the
constraint: a free profile expires in seven days, a paid one in a year, and
Android means either the Play Store or teaching people to sideload. The web app
updates by deploying.

**The web app is not the bottleneck today.** Measured this session: the music
page's queries take 87ms server-side, and the *route* takes 843ms. A native app
on the same relay would be just as slow. Rewriting the client does not fix a
network problem.

**Things that are done would be undone.** Offline shell, download queue with
pause and resume, service worker, failover between addresses, the whole media
centre UI. All of it works, and none of it survives the move.

## The middle path, which is what exists now

Tauri wraps the web app and a native plugin handles only the things the web
platform cannot do. `NativeOfflineBridge.md` scopes it: storage, background
transfer, background audio. Three narrow pieces of Swift behind one interface,
with every screen still shared.

That is roughly **ten days** against **months**, and it keeps one codebase.

## What would change the answer

Reasons to reconsider, written down so the decision is not relitigated on a
bad day:

- **Distributing to other people.** Then "install our app" beats "install our
  app, then Tailscale, then join my tailnet".
- **The gesture tax stops being occasional.** If native-feel bugs arrive faster
  than they can be fixed, the shell is the wrong shape.
- **Someone else joins the project.** Two people can carry two codebases; one
  cannot.
- **CarPlay or Android Auto matters.** Neither is reachable from a webview.

## Recommendation

**Not yet.** Do `NativeDownloads.md` and `NativeOfflineBridge.md` first — about
ten days — because they buy the three things a native app is actually wanted
for: unlimited storage, background downloads and background audio. Keep one
codebase and every screen shared.

Then reassess. If the gesture and platform-integration complaints keep coming
after that, the bridge is already the seam a native client would grow from —
the plugin interface does not change, only what sits above it.

The one thing worth doing regardless: **stop the app relaying**. 843ms against
37ms is a bigger difference than any client rewrite would deliver, and it is a
router setting rather than a codebase.

## Not decided
