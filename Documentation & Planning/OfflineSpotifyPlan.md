# Offline, redesigned — a Spotify-style download system

**Issue:** S-144 · **Status:** proposed · Supersedes the stalled OfflineRebuild
steps where they conflict.

## Why this exists

The offline system does not work on the phone, and the map of why is damning:
**the native disk store built in "Step 2" is orphaned.** Every real download and
playback path (`download-button.js`, `downloads-page.js`, `player.js`,
`watch.js`, `reader.js`, `offline-shell.js`) imports `downloads.js` directly and
never touches the `offline/storage.js` abstraction. So on iOS every download
still goes to IndexedDB — capped near 1 GB, silently evicted — and the native
files/`free_space`/disk work is dead code the app never calls.

On top of that, "is anything downloaded?" is answered by a hidden iframe probing
IndexedDB on the *server* origin (`offline-probe.html`), because the `tauri://`
shell and the server app are different origins that share no storage. That probe
depends on a service-worker cache iOS evicts — which is the "downloaded music
shows online but says nothing local offline" bug.

## The target experience (Spotify model)

- A **download toggle** (down-arrow) on every track, album, movie, show, book.
  Tap to download; filled when done; tap to remove. Progress inline.
- **"Download" switch on a collection** (album, show, playlist) that pulls the
  whole thing and keeps it there.
- **One "Downloaded" view** — everything on the device, always openable, works
  identically online and offline.
- **Offline is invisible.** No "offline mode", no server-select detour. When the
  network drops the app keeps working, plays downloaded content, and greys out
  what is not on the device. A dedicated screen appears only in the true dead
  end: nothing downloaded *and* no connection.

## Architecture — three layers, built in order

### Layer 1 — Wire the native store in (unblocks everything)

Route every caller through `offline/storage.js` instead of `downloads.js`:
`download-button.js`, `downloads-page.js`, `player.js`, `watch.js`,
`reader.js`, `offline-shell.js`. The abstraction already exposes
`save/open/has/remove/list/space` and picks `native` on a device, `indexeddb`
in a browser. After this, iOS downloads land as real files via `media_save`,
the 1 GB cap is gone, and the space gate reads real disk (`free_space`).

The IndexedDB backend stays as the browser/desktop implementation — this is a
migration of callers, not a deletion.

### Layer 2 — A self-describing native store (fixes the offline bug at the root)

Today `native.list()` joins Rust disk contents against IndexedDB metadata rows,
so a metadata loss orphans real files and — worse — that metadata is
origin-partitioned, which is why the shell can't see downloads offline.

Make the store own its metadata **on disk**:

- `media_save(id, bytes, meta)` writes the file *and* a sidecar manifest entry
  (`<id>.json`: title, kind, artist/show, artwork id, size, downloadedAt, the
  playback id the player needs). Atomic, next to the bytes.
- `media_list()` returns the full manifest for every stored item — read from
  disk, no IndexedDB. `media_manifest(id)` for one.
- Artwork is downloaded alongside and stored as a media item too, so a
  downloaded library renders with covers offline.

Now the shell can ask the native store directly what is downloaded — the
`offline-probe.html` iframe and its evicted-cache fragility are gone on device.
(The browser backend keeps using IndexedDB metadata; only native changes.)

### Layer 3 — The Spotify UX on top

- **"Downloaded" view** driven by `storage.list()` — the self-describing
  manifest — so it is correct offline with no server round-trip.
- **Invisible offline**: the connect screen stops being a gate. On launch, if a
  server is saved, go straight to the app; the app detects offline itself and
  renders the downloaded library from the manifest. The server-select screen
  becomes recovery-only (a bad/renamed address), not the front door.
- **Collection download switches** and a **sync** that keeps a downloaded
  collection current.

## The risk to retire first

Before building Layer 3 on top, prove on a real iPhone that a downloaded **video
seeks** — that iOS WebKit honours range requests on a file served via
`convertFileSrc`/the asset protocol. If it does not, serving needs a different
approach and that must be solved before the UX is built. This is Layer 1's
verification gate.

## Login/server (separate track, S-143, deferred)

The combined sign-in screen is paused. With "invisible offline" the connect
screen stops being a front door anyway, which changes its design — revisit sign-
in after offline works. The `/native/csrf` endpoint added for it is harmless and
can stay or be reverted; note it is currently uncalled.

## Order of work

1. **Layer 1 + seek gate:** wire native storage into the download + playback
   paths behind the existing abstraction; download a video on device; confirm
   play + seek offline. *(Smallest change that makes downloads real on iOS.)*
2. **Layer 2:** self-describing on-disk manifest; drop the IndexedDB/iframe
   dependency for the native path.
3. **Layer 3:** Downloaded view, invisible offline, collection sync.

Each layer is a PR with its own tests and a changelog, and nothing is called
done until it is verified on the device.
