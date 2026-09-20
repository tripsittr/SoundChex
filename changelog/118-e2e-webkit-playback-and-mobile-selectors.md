# 118 — e2e: make the WebKit mobile suite pass in a full run (S-28)

S-28 was "four specs fail only in a full run." The earlier storage-isolation
work (#140) cleared the desktop failures; this clears the remaining WebKit
(`mobile` project) ones. Four distinct causes, none of them a product bug.

## 0. A leaked offline flag failed the rest of the project

The real "only in a full run" cause. `connection-toast.spec.js`'s *"going
offline says so"* called `page.context().setOffline(true)` and never came back.
`setOffline` persists on the browser context, and the single-worker mobile run
reuses it — so every spec after it started offline, and each hung in its own
`beforeEach` on `page.goto`, timing out. One imbalanced test failed ~60 others.
Fixed with an `afterEach` that resets `setOffline(false)` unconditionally, so a
test that goes offline — or fails mid-offline — cannot strand the flag.

## 1. `phone.spec.js` measured the wrong list

*"the groupings sit above the library, not below it"* compared the music
sub-nav against `page.locator('ol').first()` — the first ordered list on the
page. There are two: the library list (`ol[data-play-queue]`, correctly below
the sub-nav) and the now-playing sheet's hidden queue (`#np-sheet-queue`), an
overlay that sits at the top of the DOM at `top: 0`. `.first()` picked the
hidden overlay, so the test measured the sub-nav against an invisible list and
declared a regression that wasn't there. Now targets `ol[data-play-queue]`.

## 2. WebKit can't decode the MP3 fixtures

The `mobile` project runs on Playwright's open-source WebKit, which — unlike
real Safari — ships **no MP3 decoder**. The seeded tracks were `.mp3`, so on
WebKit the audio element never left `readyState 0`, `startPlaying()` never saw
the clock advance, and every playback spec in `player-session.spec.js` stalled.

Fixed by seeding a **WAV/PCM converted copy** (`converted_path`) alongside each
MP3. Streaming already prefers the converted copy — that column exists exactly
because "the original may be a container or codec no browser decodes"
(`MediaItem::playbackPath()`) — so this both fixes the specs and now exercises
the converted-copy path itself. The seed tone is also now a real `sine` rather
than silence, so a stalled decoder can't masquerade as a playing one. Real iOS
Safari decodes MP3 fine; this was purely a harness codec gap.

## 3. "a paused track stays paused" raced the session save

The test paused via `el.pause()` then waited a fixed 400 ms before navigating.
The pause handler sets the intent and saves it asynchronously; navigating
before that save committed carried a stale "playing" state into the reader,
which the first tap then resumed — an intermittent failure. The test now polls
until the pause is persisted before navigating.

Relatedly, *"the first tap resumes it"* asserted `el.paused === true` on the
reader before any gesture. That is the browser's autoplay policy, not the app's
behaviour: Chromium refuses the un-gestured `play()` and reports paused, WebKit
under automation permits it. The test now asserts the app's own preserved
intent (`wantedPlaying`) — which is deterministic across engines — and only
checks the gesture-to-resume flow where the engine actually enforced the pause.

## Verification

- `player-session.spec.js` on WebKit: 4/4, run three times, stable.
- Same spec on Chromium (`desktop`): 4/4 — no regression.
- Full `mobile` (WebKit) project: green.

The `mobile-offline` project's `write-queue.spec.js` still times out in its
`beforeEach` on WebKit (all 7, deterministically in isolation). That is a
separate, pre-existing service-worker issue with no bearing on this audio or
offline-flag work — tracked as #278, not blocking S-28.
