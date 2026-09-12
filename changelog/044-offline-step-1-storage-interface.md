# 044 — Offline rebuild, Step 1: one storage interface

**Merged** 2026-09-12 · **Issues** S-107

The seam the rest of the rebuild hangs on. A single module owns offline
persistence; everything else talks to it and never learns which backend is
live. Today that backend is IndexedDB; at Step 2 a phone gets native files, and
no call site has to change.

## What changed

### `resources/js/offline/storage.js`

The interface, from the plan:

```
save(id, source, { type, bytes })   store a file
open(id)                            a seekable URL, or null
has(id)                             whether it is genuinely stored
remove(id)                          delete it
list()                             metadata for everything stored
space()                            free bytes, and whether that number is real
```

`resume()` and a streaming `save()` are declared on the contract for the native
backend to fill in — films and books need them — so the shape does not shift
under callers when Step 2 lands.

### Two backends, chosen by capability

- **`indexeddb`** — the live one. It delegates to the proven store operations in
  `downloads.js` rather than reimplementing them, so today's behaviour (crucially
  the Safari ArrayBuffer workaround — Blob-in-IndexedDB aborts the transaction on
  WebKit) is preserved exactly.
- **`native`** — stubbed and throwing until Step 2.

`detectBackend()` chooses by the Tauri globals, not user-agent, so a browser tab
keeps working and a phone gets the real thing without either knowing.

### One write primitive

The blob-and-metadata write was inline in the download path. It is now
`storeBlob()`, exported from `downloads.js` and called by both the download path
and the storage interface — so the ArrayBuffer workaround lives in exactly one
place.

### `space()` is honest about what it knows

The IndexedDB backend can only offer the browser *quota* — a slice the engine
set aside, bounded near 1 GB on iOS, not the device's disk. So it reports
`{ known: true, source: 'quota' }` and, when it cannot answer at all,
`known: false` — a caller is never handed a confident wrong number. The native
backend will answer from `statvfs` at Step 2a.

## Worth knowing

- **The seam is established but not yet the only path.** `media-center.js`
  imports the module so it loads and picks a backend on every app page, and the
  download path shares `storeBlob`; migrating the download button, queue and
  player to route *through* the interface is deferred to later steps so the
  working download path is not rewritten in one move.
- No behaviour changed for a user. This is structure.

## Still wrong

- The native backend does not exist yet (Step 2). Inside Tauri the interface
  still uses IndexedDB, so the ~1 GB ceiling is unchanged until then — Step 1
  makes the fix a one-line backend switch, it is not the fix itself.

## Tests

`storage-interface.spec.js` round-trips a file through the interface (save →
has → open → list → remove) and asserts the backend choice and the `space()`
shape, on **both** Chromium and WebKit — the WebKit pass is what proves the
ArrayBuffer path survived the extraction. The mobile download suite (23) still
passes. Vitest 98 · PHP 568. The `mobile-offline` project stays red on the
specs Steps 2–6 fix.
