# Offline rebuild — task checklist

A phased, tickable breakdown of `OfflineRebuild.md` (S-107), for tackling the
~9-day rebuild incrementally across sessions rather than in one sitting. Each
task is sized to be finished and verified on its own; the **Verify** line is
what tells you it is actually done, not just written.

**Order is load-bearing.** Step 0 first (it makes every later step checkable),
then 1 (the interface the rest depends on), then the rest mostly in sequence.
Android slots in after Step 2. Do not start a step whose dependency is unchecked.

Each step is worth its own PR and changelog entry. Nothing here deletes the old
system until Step 6, so the app keeps working throughout.

---

## Step 0 — Make the failure visible (~1 day) · no dependency

The point is a **red** suite that describes the real bug. Today's green is the bug.

- [ ] Add `offline`, `offline-shell`, `offline-probe`, `library-mirror`,
      `write-queue`, `service-worker` to the `mobile` project's `testMatch` in
      `playwright.config.js`.
- [ ] Keep the same specs running on Chromium too (a pass-on-one, fail-on-other
      split is the signal being bought).
- [ ] Add one spec that writes past 1 GB and asserts the failure is **reported**,
      not swallowed.
- **Verify:** the new WebKit runs are red, and the failure messages name a
  quota/write failure rather than timing out silently.
- **Ship:** PR with the failing specs; no production code changed.

## Step 1 — One storage interface, two backends (~1.5 days) · needs Step 0

A single `offline/storage.js` module owns persistence; nothing else learns which
backend is live.

- [ ] Define the interface: `save(id, stream, {type, bytes})` (streams, never
      buffers whole), `resume(id, from)`, `open(id)` (seekable URL), `remove(id)`,
      `list()`, `space()` (real free bytes).
- [ ] Implement the `indexeddb` backend by moving today's logic behind it —
      behaviour-preserving for the browser/desktop-web path.
- [ ] Stub the `native` backend (filled in Step 2) so capability detection can
      choose between them at startup — detection, **not** user-agent.
- [ ] Route the download button, queue, player and shell through the interface
      only.
- **Verify:** the full existing offline suite still passes on Chromium through
  the `indexeddb` backend; no call site references IndexedDB directly any more.
- **Ship:** PR; browser behaviour unchanged, native backend not yet live.

## Step 2 — Native storage on iOS (~2 days) · needs Step 1

- [ ] **First, de-risk:** prove Tauri's asset protocol honours range requests on
      a real device. If it does not, stop — seeking in a downloaded film would
      break, which is worse than today.
- [ ] Write the `native` backend: files in `Library/Application Support/media/`,
      marked `isExcludedFromBackup` (not `Caches/`, not `Documents/`).
- [ ] Serve files back through the asset protocol; confirm a film seeks without
      being resident in memory.
- [ ] Capability detection selects `native` on device, `indexeddb` in a browser.
- **Verify:** on an iPhone, download a file larger than 1 GB, play it, and seek
      mid-file; confirm free-space ceiling is tens of GB.
- **Ship:** PR; device-tested, with the range-request proof in the description.

## Step 2a — Real disk numbers (~0.5 day) · needs Step 2 (pairs with it)

- [ ] Add a `free_space(path) -> u64` Tauri command (bytes available), using
      `statvfs` on macOS/iOS/Linux/Android and `GetDiskFreeSpaceExW` on Windows.
      No crate needed; follows the existing `start_service` command pattern.
- [ ] Watch the Darwin struct-layout trap: `f_bsize`/`f_frsize` are 64-bit,
      block counts 32-bit. Return bytes, not a struct.
- [ ] `space()` in the storage interface reads this natively, `estimate()` only
      in a browser.
- **Verify:** the command's result matches `df -k` exactly on each platform
      (it did on this Mac: 7.1 GB of 494.4 GB). Test asserts against `df`, not
      against itself.
- **Ship:** folded into the Step 2 PR or its own small one.

## Step 3 — One database, three stores (~1 day) · needs Step 1

- [ ] Collapse `soundchex-downloads`, `soundchex-library`, `soundchex-writes`
      into one database with three object stores and one connection manager
      (open, recover-on-close, retry), written once.
- [ ] First-launch migration: copy across, verify, release the old. Version bump
      happens once here, not per-store forever.
- **Verify:** on a device/browser holding existing downloads, the migration
      preserves every blob (count and bytes match before/after) and the old
      databases are gone.
- **Ship:** PR; call out the one-time migration in the changelog as data-touching.

## Step 4 — Background transfer (~2 days) · needs Step 2

- [ ] Downloads become `URLSession` background tasks handed to the system;
      continue while suspended, wake on completion.
- [ ] The queue becomes a persisted description of intent that survives the
      process, not a loop that dies with the webview.
- [ ] Include background audio and lock-screen controls (same root cause).
- **Verify:** start a multi-file download, background the app, lock the phone;
      downloads continue and audio keeps playing with working lock-screen controls.
- **Ship:** PR; device-tested.

## Step 5 — Gate on free space, honestly (~0.5 day) · needs Step 4

Two-thirds done ahead of plan (S-111). Remaining:

- [ ] `space()` already comes from the backend after Step 2a — confirm the gate
      reads the real number, not `navigator.storage.estimate()`.
- [ ] **The real remaining work:** a running batch that hits a full disk
      **pauses and says so**, keeping what it has, rather than failing every
      remaining item in turn (the S-21 shape). Needs the Step 4 queue.
- [ ] Confirm the four-outcome behaviour: comfortably fits / fits-but-tight
      (warn, name the numbers) / does-not-fit (refuse, name the shortfall) /
      unknown (ask). The single-large-item path uses the same gate.
- [ ] Every refusal and failure logs attempt, response, and item id (rule 7).
- **Verify:** fill a device mid-batch (or simulate) and confirm it pauses with
      a message rather than erroring out item by item.
- **Ship:** PR.

## Step 6 — Delete the old system (~0.5 day) · needs Steps 1–5

- [ ] Remove the old IndexedDB-direct paths now that the interface owns
      persistence. `useLocalSource()` is already dead (S-38).
- [ ] Confirm no code reads a removed path; two live systems means the next bug
      hides in the unread one.
- **Verify:** full offline suite green on both engines; grep confirms the old
      store names appear only in the migration.
- **Ship:** PR.

---

## Android (~4.5 days) · needs Step 1 (slots in after Step 2)

Parallelisable with iOS once the storage interface exists.

- [ ] Generate the project and prove a build (~1 day) — Android Studio, NDK,
      signing key. Budget the day for toolchain, not code.
- [ ] Storage backend (~1 day) — `getExternalFilesDir()` behind the Step 1
      interface.
- [ ] Serve files back (~0.5 day) — Tauri's asset protocol on Android must honour
      ranges; prove it as on iOS.
- [ ] Background transfer (~1.5 days) — `WorkManager` for the queue.
- [ ] Scoped storage and permissions (~0.5 day).
- **Verify:** same device checks as iOS — >1 GB download, seek, background,
      lock-screen audio — on a real Android device.

---

## Sequencing summary

```
Step 0  →  Step 1  →  Step 2  →  Step 2a
                   ↘  Step 3        ↓
                              →  Step 4  →  Step 5  →  Step 6
                   (after Step 2) →  Android
```

**Total ~9 days + ~4.5 for Android.** One step per PR; the app works throughout
until Step 6 retires the old path.
