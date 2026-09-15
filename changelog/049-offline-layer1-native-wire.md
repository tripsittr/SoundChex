# 049 — Offline Layer 1: wire the native store in, and stream

**Branch** offline-layer1-native-wire · **Issues** S-144

The native disk store built earlier was never used: every download and playback
path imported `downloads.js` directly and bypassed the storage abstraction, so
on iOS downloads still went to IndexedDB — capped near 1 GB and silently evicted.
This is why offline "didn't work at all" on the phone. Layer 1 connects the
store the app already had.

## What changed

### Every caller now goes through the abstraction

`download-button.js`, `downloads-page.js`, `player.js`, `watch.js`, `reader.js`
and `library/offline-shell.js` now import storage operations
(`download`/`localUrl`/`list`/`remove`/`isDownloaded`) from
`offline/storage.js` instead of `downloads.js`. On a phone that resolves to the
native disk backend; in a browser, to IndexedDB — unchanged. The pure helpers
(`formatBytes`, `checkSpace`, `storageEstimate`) stay in `downloads.js`.

`storage.js` gains a `download()` that fetches a URL and stores it through the
live backend, plus `localUrl`/`isDownloaded` aliases so migrating a caller is an
import swap, not a rename through its body.

### Streaming, so a film is never held whole in memory

The old `media_save` turned the whole file into one JS byte array and one Rust
`Vec` — the file resident twice — which made multi-GB downloads impossible on
the phone. New Rust commands stream instead:

- `media_append(id, chunk)` appends one chunk to the `.part` file.
- `media_finalize(id, meta_json)` renames it into place and writes its manifest.
- `media_save` stays for small one-shot writes (a track, artwork).

The JS `download()` and native `save()` read the response/blob a chunk at a time
(4 MB) and append each, so nothing larger than a chunk crosses the IPC bridge.

### A self-describing store (foundation for Layer 2)

`media_finalize`/`media_write_manifest` write a `<id>.meta` JSON sidecar beside
the bytes. `media_list` now returns `(id, size, manifest_json)` read straight
off disk, and `native.list()` parses it — no IndexedDB. This is what will let
the download list be correct offline and visible to the shell without the
origin-probe iframe (completed in Layer 2). `media_remove` clears the manifest
and any stray `.part` too.

## Worth knowing

- **The browser path is unchanged** — the IndexedDB backend still owns it, and
  callers reach it through the same abstraction. Verified: build clean, Vitest
  98, download vitest 25.
- **The native path needs device verification** — that a real download lands on
  disk, lists, plays, and (the plan's key risk) **seeks** offline. That is this
  branch's gate before merge.
- The e2e Playwright suite could not run this session: its `--env=e2e` database
  points at a stale path from a previous checkout and its seeder hits a
  pre-existing Spatie permission-cache error, unrelated to these changes.

## Still to do (later layers)

- Layer 2: drop the `offline-probe.html` iframe now that the store is
  self-describing; the shell reads `media_list` directly.
- Layer 3: the Spotify UX — a Downloaded view off the manifest, download toggles
  everywhere, invisible offline.
- The space gate still reads `checkSpace` (browser quota); moving it to the
  disk-aware `space()` is a follow-up.

## Tests

Rust `cargo test --lib` 5 (path-safety + free_space); desktop and iOS-sim
compile warning-free. JS build clean; Vitest 98; download vitest 25. Native
disk path pending on-device verification.
