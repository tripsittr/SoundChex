# 046 — Offline rebuild, Step 2: native storage on iOS

**Merged** 2026-09-13 · **Issues** S-107

The fix for "offline doesn't work on the phone." Downloads move off IndexedDB —
capped near 1 GB on iOS and evictable without warning — onto real files on the
device's disk, where the ceiling is free space. The code is complete and
verified as far as a simulator and unit tests reach; **one check, that a
downloaded film can seek, still needs a physical iPhone** and is called out
below.

## What changed

### The native store, in Rust

`lib.rs` gains the media commands, mobile-only:

- `media_save(id, bytes)` — writes to a temporary `.part` file and renames it
  into place, so a crash mid-write never leaves a half-file that reads as a
  finished download. Marks the file excluded from backup (a `setxattr` with the
  documented `com.apple.metadata:com_apple_backup_excludeItem` value) — a local
  cache of the server's library should not fill iCloud.
- `media_exists` / `media_path_for` / `media_remove` / `media_list`.

Files land in `$APPDATA/media/` — not `Caches/` (purged under pressure) and not
`Documents/` (surfaced in the Files app). The id comes from the web layer and is
untrusted, so every path goes through `safe_media_path`, which refuses a
separator, `..`, or anything whose parent is not the media directory.

### The native backend, in JS

`storage.js`'s `native` backend calls those commands. `save` serialises the
blob and stores metadata in IndexedDB (small; the download list is built from
it) while the bytes go to disk. `open` returns a `convertFileSrc` URL — Tauri's
asset protocol, which honours range requests so a film can seek. `space` reads
the real disk via the Step 2a command.

### Serving files back

`assetProtocol` is enabled and **scoped to `$APPDATA/media/**`** in
`tauri.conf.json`, the `protocol-asset` Cargo feature added, and the CSP widened
to allow `asset:` media sources. This is the plumbing that lets a downloaded
film be served seekably rather than held in memory.

### Turning it on: a probe, not a flag

`detectBackend()` is now async and **probes** — it invokes `media_exists` on a
sentinel id and picks `native` only if the shell answers. A browser has no
bridge; an older shell rejects the unknown command. So native storage turns on
exactly where it works, with no version flag to keep in sync and no user-agent
sniffing. The interface functions await the resolved backend.

## Worth knowing

- **Verified without a device:** the iOS bundle builds, installs, and launches
  on the iOS 26 simulator without crashing — which proves the command handler
  and asset-protocol config are wired correctly, since a bad registration
  crashes on launch. `cargo test --lib` (5 tests) covers the path-traversal
  defence and the disk-space command. The browser path is unchanged
  (storage-interface 3/3, Vitest 98).
- **The seam is built but not yet the sole path.** The download button, queue
  and player still call the old `downloads.js` directly; routing them through
  the interface is Step 6, once the native path is device-confirmed.
- ~17 GB of stale build caches were cleared this session — the Mac was down to
  2.6 GB free, which is what made the iOS build fail with `ENOSPC` and likely
  contributed to the flaky device connection.

## Still wrong — the one thing a simulator cannot prove

- **Whether a downloaded film seeks on a real device is unconfirmed.** The plan
  names this the largest risk: Tauri's asset protocol must honour HTTP range
  requests on iOS Safari, and the simulator's WebKit is not the device's. The
  code is in place and ready; the test — download >1 GB, play, seek mid-file —
  needs an iPhone with a working data cable (the one tried this session carried
  no data). If the range request is not honoured, `open()` will need a fallback
  rather than serving a film that cannot scrub.
- Native `save` still serialises the whole blob to memory for the write; the
  streaming form (`resume`/chunked) is a later refinement. It matches what the
  IndexedDB path already did, on a store no longer capped at a gigabyte.

## Tests

Rust `cargo test --lib`: **5 passed** — path-traversal defence (accepts a plain
id, refuses `..`/separators/absolute/empty, keeps the parent inside the dir) and
the disk-space command against `df`. iOS-sim and desktop both compile
warning-free. Vitest 98; storage-interface 3/3 on Chromium and WebKit.
