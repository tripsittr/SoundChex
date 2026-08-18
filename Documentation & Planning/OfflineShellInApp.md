# Ship the offline shell inside the app

## The flaw

The Tauri app contains **no web files at all** — verified: zero `.html`, `.js`
or `.css` in the built `.app`. It ships a connect screen and nothing else.
Every screen, including the offline shell built in Phase 3, is served *by the
Laravel server* and reaches the device only through the service worker cache.

So the offline capability depends on a cache the app does not control:

- A fresh install has never populated it.
- iOS evicts service worker storage from apps that have not been opened
  recently — precisely when someone next reaches for a library they downloaded
  for exactly this situation.
- Clearing website data clears it.

Reproduced: with no service worker cached and the server unreachable, a cold
launch lands on the browser's own error page. Earlier tests passed only
because they visited while online first, which warmed the cache — a condition
the real failure does not have.

The offline work is real. It is reachable only under conditions that do not
hold when it matters.

## The fix

Embed the offline shell in the app bundle, so the device can render its own
library with nothing available but itself.

Measured cost: **~35 KB of JavaScript and ~788 KB of CSS**, against a 4.4 MB
app. Not a size problem.

### 1. Copy the built assets into the shell at build time (~half a day)

A Vite step writes the offline bundle into `public/tauri/assets/` alongside the
connect screen, which Tauri already ships. Content-hashed names are resolved
through the manifest, which is copied too — the same lookup the offline page
already does, but reading a local file rather than the network.

### 2. Fall back to the embedded copy (~half a day)

When no address answers, the connect screen renders the library **itself**
rather than navigating to a server that is not there. It already has the
mirror: `soundchex-library` is IndexedDB on the app's own origin, so no request
is involved.

The current fallback navigates to `origin + '/app'` and hopes the service
worker answers. That works when the cache is warm and fails completely when it
is not.

### 3. Keep the server path primary (~an hour)

Nothing changes while the server is reachable: it still serves every page, and
the embedded copy is only reached when nothing answers. The embedded shell is
a floor, not a replacement — it renders the catalogue and plays downloads, and
says plainly that it is doing so.

### 4. Tests that cannot pass by accident (~half a day)

The existing offline tests warm the cache first, so they would pass against
the broken build. New ones must start from a device that has **never** been
online: no service worker, no HTTP cache, only IndexedDB and the app bundle.

**Total: ~1.5 days.**

## Why not just cache more aggressively

A service worker cannot install itself without one successful request, and iOS
evicts it on its own schedule. Anything that depends on it is a cache, not a
guarantee — and the point of downloading music is that it is there regardless.

## Built

`scripts/embed-offline-shell.mjs` copies the offline bundle into
`public/tauri/offline/` after every `vite build`, resolving an entry's imports
transitively — copying only the entry leaves it importing files that are not
there. Tauri compiles that directory into the binary, verified by finding both
content-hashed filenames inside the built executable.

When no address answers, the connect screen renders the library itself rather
than navigating to a server that is not there. Everything it needs is local:
the assets ship in the app, and the catalogue is IndexedDB on the app's own
origin.

Tests run against the shell on its own static server, and never visit the
Laravel one while online — the earlier offline tests did, which warmed the
service worker cache and let them pass against a build with no offline
capability at all. Verified by deleting the embedded assets: all four fail,
which is the state the app shipped in before.

One thing worth remembering: Playwright's `setOffline` blocks same-origin
requests too, so it cannot be used to test this — it stops the shell loading
its own assets, which real offline does not. Only the server is routed to
abort.
