# 050 — Offline downloads and playback, on the device

**Issues** S-144 · Supersedes the stalled OfflineRebuild steps.

The fix for "offline doesn't work at all on the phone." Downloads now write to
real files on the device's disk and play back with no connection — verified end
to end on an iPhone.

## What changed

### Downloads land on disk, not IndexedDB

Every download and playback path (`download-button`, `downloads-page`, `player`,
`watch`, `reader`, `offline-shell`) now goes through the `offline/storage.js`
abstraction instead of `downloads.js` directly. On a phone that resolves to the
native disk backend; in a browser, IndexedDB — unchanged. So iOS downloads are
no longer capped near 1 GB or silently evicted.

Large files stream to disk a chunk at a time (`media_append`/`media_finalize`)
rather than being held whole in memory, and each download writes a `.meta`
manifest beside its bytes so the store is self-describing — the download list is
read straight off disk, offline, with no origin-partitioned IndexedDB probe.

### The reason it never worked: the remote origin

The app is served from the user's remote `.ts.net` server, and Tauri v2 treats
that origin very differently from the local connect page. Two things were
missing, each failing silently:

- **The bridge was not injected.** `window.__TAURI__` is not put on a remote page
  by `withGlobalTauri` or `remote.urls`. It is now injected from Rust with
  `initialization_script_for_all_frames` (the `@tauri-apps/api` IIFE built by
  `scripts/build-tauri-bridge.mjs`), and the JS waits for it before probing.
- **The commands were denied.** App-crate commands get no ACL permission by
  default, and remote content is subject to the full ACL — so `media_save` and
  friends worked on `tauri://` but were denied on the server origin, and every
  download quietly fell back to IndexedDB. `build.rs` now generates
  `allow-<command>` for each via `AppManifest::commands`, and the capability
  grants them (hyphenated) scoped to the `.ts.net` origin.

### Offline playback

`player.load()` now resolves a downloaded copy *before* touching the network, so
a downloaded track offline plays from disk instead of erroring on the
unreachable server URL first. And `ensurePlayback()` — extracted from
`now-playing.js` — creates the player and binds the play buttons independent of
the server-rendered now-playing bar, so the offline shell (which has no bar) can
start playback. Tapping a downloaded song offline now plays it.

### The space gate reads the real disk

`confirmSpace()` reads free disk from the storage abstraction (`statvfs` via
`free_space`) instead of the browser quota, which on iOS is bounded near 1 GB —
so movies no longer read as "won't fit" and refuse to download.

## Verified on device

Downloaded two songs on an iPhone: files present on disk under
`Library/Application Support/net.soundchex.client/media/` with `.meta` manifests,
`download:stored backend=native`, full and untruncated. Cold-opened offline: the
downloads listed, and tapping one played it with no connection.

## Still to do — the offline UI (Phase 3, OfflinePhase3Plan.md)

Offline currently renders a minimal fallback shell, not the full app UI. Making
offline look identical to online — the client-render "Phase 3" the codebase
defers — is tracked separately: an artwork blob cache (offline covers are glyphs
today), full home/detail/filter renderers, and genres/playlists in the sync.

## Tests

Rust `cargo test --lib` (path-safety, disk space); iOS and desktop compile
warning-free; Vitest 98; the browser download path unchanged. The native disk
path is device-verified rather than unit-tested (it needs the real Tauri bridge
and filesystem).
