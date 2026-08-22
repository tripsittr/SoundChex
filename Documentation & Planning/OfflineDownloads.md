# Offline Downloads

Take music, books and films with you — on a flight, a commute, or anywhere the
server isn't reachable.

## Why this is different here

Every commercial service restricts offline: a rental window, a device cap, a
title that silently disappears. None of that applies to files you own. The only
real constraint is the browser's storage budget.

## How it works installed to the home screen

An installed PWA shares storage with the browser it was installed from, so
downloads behave identically either way. Two facts shape the design:

**Storage is capped and evictable.** Chrome and Android allow a large share of
free disk; **iOS Safari caps a PWA at roughly 1 GB** and may evict it under
pressure. `navigator.storage.persist()` asks the browser not to — it is granted
for installed apps far more often than for a plain tab, which makes
add-to-home-screen the recommended path rather than a nicety.

**Cache API cannot serve range requests.** A film cached that way plays from
the start and refuses to seek. Downloads therefore go to **IndexedDB as blobs**,
and playback reads from a blob URL, which seeks natively.

The service worker is registered but currently only caches build assets and
artwork; it does not handle downloads at all.

## Scope

**In:**

- A download manager in IndexedDB: request, progress, cancel, delete
- Quota check before starting, with an honest warning when a title is large
  relative to free space
- `navigator.storage.persist()` on first download
- Player and reader read from a local blob when one exists, without asking the
  server
- A "Downloads" screen listing what is stored, with sizes and a way to free
  space
- Clear handling of eviction: say the file is gone, offer to fetch it again

**Out:**

- Background download continuation after the app closes. The Background Fetch
  API is Chromium-only; a download resumes when the app is reopened instead.
- A smaller offline rendition of video. That needs the transcode ladder HLS
  will build; when it exists, this can offer it.
- Encryption at rest. These are the user's own files on the user's own device.

## Approach

1. `downloads.js` — an IndexedDB wrapper: one store for blobs, one for
   metadata, a stream that reports progress and can be aborted.
2. Quota via `navigator.storage.estimate()`. Refuse only when it certainly
   won't fit; warn when it's close. **The user decides**, since a 1.9 GB film
   on a desktop browser is fine and on iOS is not.
3. A download button on the detail page, showing state: available, downloading
   with progress, stored with size, or unavailable offline.
4. Playback prefers a stored blob and falls back to the network, so an offline
   film plays from the same player with no separate mode.
5. A Downloads screen at `/app/downloads`, listing stored items with a total
   and per-item delete.

## Constraints

- **Downloads are per profile.** A kids profile must not carry an R-rated film
  onto a device. `ContentGate` applies before a download is allowed, exactly as
  it does for streaming.
- Blobs must be released with `URL.revokeObjectURL()` when a player unloads.
  Holding them pins the memory of a multi-gigabyte file.
- A partial download must never be playable. Store the blob only once the
  transfer completes, and mark the record only after that.
- iOS gives no warning before eviction. Every read must handle "it is not
  there" rather than assuming a record means a file.

## Verification

- Download a track, go offline, play it.
- Download a book, go offline, read it — including resume position.
- Attempting a title larger than free space warns before starting, and the
  warning states both figures.
- Cancel mid-download leaves nothing behind.
- Delete frees the space, confirmed via `storage.estimate()`.
- A kids profile cannot download a capped title.
- Removing a blob by hand and reopening shows the eviction message rather than
  a broken player.

## Outcome

Built. **Not yet verified in a browser** — see below.

- `downloads.js` stores files in IndexedDB as blobs, streams with progress,
  supports cancel, and prunes records whose blob the browser reclaimed.
- The blob is written only after the transfer completes, so a cancelled or
  failed download leaves nothing playable behind.
- `navigator.storage.persist()` is requested on the first download.
- A quota check warns before starting when a title is large relative to free
  space, naming both figures — it advises rather than refuses, because 1.9 GB
  is fine on a desktop and impossible on iOS.
- Download button on the detail page with four states; `/app/downloads` lists
  what is stored with sizes and per-item delete.

Corrected an assumption made while building: the service worker **was** already
registered, from `media-center.js`. An earlier grep of the layout alone found
nothing and I wrongly concluded it was never registered.

Verified from the server: routes render, the button carries the right size and
source URL (stream for media, `/read/{id}/file` for books), and a kids profile
gets 404 on both the detail page and the stream — so a capped title cannot be
downloaded even by crafting the request. Tests 9/9.

## Still to verify

The parts that only exist in a browser, which the server cannot exercise:

- Downloading a track, going offline, and playing it
- Downloading a book and reading it offline, including resume position
- The quota warning firing on a device where the file genuinely doesn't fit
- Eviction handling, by clearing site data and reopening

## Deferred

**Playback from the local blob.** `useLocalSource()` exists in
`download-button.js` but is not yet wired into the player or reader, so a
downloaded file is stored and listed but still streams from the server when
played. That is the next piece of work, and until it lands the feature is
storage without offline playback.
