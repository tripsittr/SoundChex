# Mobile Access & Offline Playback

Make the library genuinely usable from a phone, on and off the network.

## The blocker, first

Downloads are built but **inert over plain HTTP**, and right now that is how a
phone reaches this server.

`soundchex.test` is HTTPS through Herd, but that certificate is trusted only on
the Mac that issued it. From a phone the server is a bare LAN IP over HTTP,
which browsers treat as an insecure context:

| | HTTPS | Plain-HTTP LAN IP |
|---|---|---|
| Service worker | yes | **no** |
| Add to home screen | yes | **no** |
| `storage.persist()` | yes | **no** |
| IndexedDB | yes | yes, but freely evicted |

So downloads store nothing durable, the app cannot be installed, and the
offline shell never registers. `media-center.js` already no-ops the service
worker on an insecure origin rather than throwing — the code is correct, the
environment is the problem.

**Nothing else in this plan is worth doing until a phone can reach the server
over HTTPS.**

### Recommendation: Tailscale

For a home server it beats a public tunnel:

- The library is never exposed to the internet — only devices on your tailnet
- No domain to buy or DNS to manage
- Real certificates via MagicDNS, so a genuine secure context
- Works identically at home and on cellular, so there is one URL to remember

Cloudflare Tunnel is the alternative when someone outside the tailnet needs
access. **`RemoteAccess.md` has the commands for both** — this plan does not
repeat them.

The one step easy to miss: after `tailscale serve`, set `APP_URL` to the
`https://…ts.net` address. Laravel builds asset and route URLs from it, and a
mismatched origin means the service worker registers against a scope the pages
are not served from.

## Then: offline playback

Downloads currently store a file, list it, and stream from the server anyway.
On a plane that is a spinner. `useLocalSource()` exists in
`download-button.js` and nothing calls it.

- Player and reader prefer a stored blob, falling back to the network
- Blob URLs revoked on unload — a live URL pins a multi-gigabyte file in memory
- The downloads screen works with no connection; it is precisely when it is
  needed
- An indicator saying playback is coming from the device, so a spinner on a
  train is distinguishable from a file that never downloaded

## Then: the mobile layout pass

- **Bottom navigation on phones.** It is pinned to the top today, which is the
  furthest point from a thumb. Every streaming app puts it at the bottom.
- **Safe areas.** The media center sets neither `viewport-fit=cover` nor
  `env(safe-area-inset-*)`, so in standalone mode content runs under the notch
  and the home indicator. The reader already handles this; the rest does not.
- Tap targets to 44px, and overscroll suppressed so a pull doesn't bounce the
  whole app.

## Then: batch downloads

An album is twelve taps. One control that queues a whole album, a book series,
or everything in My List, with a single progress bar and a single cancel.

## Out of scope

- Background downloads that continue after the app closes. Background Fetch is
  Chromium-only; a download resumes when the app is reopened.
- Auto-download rules (keep My List offline). Worth doing, but only once manual
  downloads are proven on a real device.

## Verification

Each step gated on a real phone, because none of this can be exercised from the
server:

1. Phone reaches the library over HTTPS; add-to-home-screen offered.
2. Installed app registers a service worker and reports persisted storage.
3. Download a track, enable airplane mode, play it from the device.
4. Download a book, go offline, read it — including resume position.
5. Navigation is thumb-reachable; nothing sits under the notch or the home bar.
6. Downloading an album shows one progress bar and one cancel.

## Outcome

Everything that does not need a phone is built. **The HTTPS step is yours to
run, and nothing offline works until it is done.**

- Player, video and reader all prefer a downloaded copy, falling back to the
  network. Blob URLs are revoked on track change and on unload — a live URL
  pins a multi-gigabyte file in memory.
- Audio and video swap the local source in *after* playback starts, so a track
  that was never downloaded doesn't pay for an IndexedDB lookup. The reader
  cannot: epub.js and pdf.js read the URL once at construction, so the choice
  is made first.
- A badge says when playback is coming from the device, so a spinner on a train
  is distinguishable from a file that never finished.
- The downloads screen is cached and served when the network is gone. It holds
  no library data — the list comes from IndexedDB — so a stale shell is safe.
- Bottom tab bar on phones, replacing the scrolling pill row at the top.
  Safe-area insets throughout, and `viewport-fit=cover`, without which
  `env(safe-area-inset-*)` reports zero.
- Album download: one action for sixteen tracks, sequential rather than
  parallel, skipping any already stored so an interrupted album resumes.

Verified from the server: routes render, badges are hidden by default, the
album button appears only for genuine multi-track albums (16 tracks on the
largest), tests 9/9.

## Still to verify — needs a phone

Nothing below can be exercised from the server:

1. Reach the library over HTTPS (see `RemoteAccess.md`), then set `APP_URL`.
2. Add to home screen; confirm the service worker registers.
3. Download a track, enable airplane mode, play it — the badge should appear.
4. Download a book, go offline, read it including resume position.
5. Open `/app/downloads` with no connection.
6. Check nothing sits under the notch or the home indicator.
