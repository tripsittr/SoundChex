# 043 — Offline rebuild, Step 0: make the failure visible

**Merged** 2026-09-12 · **Issues** S-107

The first step of the offline rebuild (`OfflineRebuildTasks.md`). It ships **no
fix** — deliberately. It ships a test suite that runs on the engine the bug
lives on, and it comes up red, because today's green was the bug hiding.

## What changed

### A WebKit project for the offline specs

The offline shell, the library mirror, the downloads store and the write queue
were only ever tested on Chromium (the `desktop` project). The device runs
WebKit, where Blob handling, closed connections and the ~1 GB IndexedDB ceiling
behave differently — so the suite that was meant to protect the offline system
could not, by construction, catch the class of bug that actually breaks it.

A new `mobile-offline` Playwright project runs
`offline`, `offline-shell`, `offline-probe`, `offline-quota`, `library-mirror`,
`write-queue` and `service-worker` on real WebKit, with the service worker
**enabled** (unlike the `mobile` project, which blocks it to keep route
interception honest). The same specs keep running on Chromium under `desktop`,
so a pass-on-one, fail-on-the-other split is now visible — which is the whole
signal this buys.

### What it exposed

On WebKit, **9 specs fail that pass on Chromium**:

- The offline shell cannot rebuild the songs list, film grid, albums, search,
  the item page, or the home screen from the device copy.
- A downloaded track does not play with the network offline.

These are not new bugs. They are the reported symptoms — "offline mode does not
work" — finally reproduced by a test instead of a phone.

### A quota-reporting test

`offline-quota.spec.js` writes blobs to the real downloads store
(`soundchex-downloads` / `blobs`) past any browser quota and asserts the failure
is *reported*, named as a quota problem. It captures the exact defect the
rebuild's storage layer must fix:

- **Chromium** rejects with a `QuotaExceededError` — the reason survives.
- **WebKit** rejects with a bare `Error` named `"error"` — the reason is lost,
  so the download path cannot tell a full disk from a broken write from a
  network blip. It settles as if nothing went wrong.

### Docs reconciled

`OfflineRebuild.md` is marked the authoritative plan; `NativeDownloads.md`,
`NativeOfflineBridge.md` and `NativeClients.md` now carry a superseded banner
pointing at it, so nobody follows an absorbed plan by accident.

## Worth knowing

- **The `mobile-offline` project is red on purpose.** 9 offline-shell/offline
  specs and the WebKit half of the quota spec fail, and will until the storage
  rebuild (Steps 1–6) lands. This is Step 0's deliverable: a suite that
  describes the real failure. `desktop`, `mobile`, `shell` and `uploads` stay
  green.
- No production JavaScript changed. This is test configuration and new specs.

## Still wrong

- Everything the red specs describe — that is the point; the fixes are Steps
  1–6.
- WebKit under Playwright is not Safari on iOS and will not reproduce the exact
  1 GB ceiling. It reproduces the API-level contract, which is the difference
  between a suite that *could* catch this class of bug and one that provably
  cannot.

## Tests

PHP 568 · Vitest 98 · Playwright `desktop`/`mobile`/`shell`/`uploads` green.
`mobile-offline`: **36 passed, 9 failed** — the failures are the deliverable.
