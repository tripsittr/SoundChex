# 045 — Offline rebuild, Step 2a: real disk numbers

**Merged** 2026-09-12 · **Issues** S-107

The space gate has been reading the wrong number. It asked the browser for its
*quota* — a slice the engine set aside, capped near 1 GB on iOS — when the
question is how much room is on the disk. This gives it the real figure, on
every platform, ahead of the native storage backend that needs it most.

## What changed

### A `free_space` Tauri command

`free_space(path) -> u64` returns the bytes actually available to the app on the
volume holding `path`. No plugin exists for this; it is one system call:

- **Unix** (macOS, iOS, Linux, Android) — `statvfs`, using `f_bavail` (blocks a
  non-root process may use) × `f_frsize`. `libc` only.
- **Windows** — `GetDiskFreeSpaceExW`. `windows-sys` only.

Registered on every platform, not just desktop — the phone is where it matters
most.

**The Darwin trap the plan warned about is avoided.** Returning a struct invites
reading `f_bsize`/`f_frsize` (64-bit) against block counts (32-bit) the wrong
way round, which produces a garbage trillion-gigabyte figure rather than an
error — and a wrong reading here refuses every download or approves every one,
silently. So the command returns a single integer, and the test asserts it
against `df`, not against its own arithmetic.

### `space()` reads the disk, not the quota

The storage interface's `space()` now prefers the native figure when the shell
can answer:

- Inside the app → `free_space` → `{ known: true, source: 'disk' }`.
- In a browser → `navigator.storage.estimate()` → `{ source: 'quota' }`, or
  `known: false` when even that is unavailable.

This corrects the number wherever the bytes are stored, so the gate stops
reading the ~1 GB quota on the phone **before** the native storage backend
(Step 2) lands — the quota was the wrong source regardless of where files go.

## Worth knowing

- `space()` currently passes `/` as the volume. The native storage backend will
  pass its actual media directory at Step 2; `/` is the correct volume on the
  single-root platforms until then.
- This needed no device: it is verified against `df` on this Mac. iOS reports
  through the same `statvfs`, but the on-device figure is unconfirmed until an
  iPhone build runs — noted for Step 2.

## Still wrong

- The native *storage* backend does not exist yet (Step 2), so files are still
  in IndexedDB and the ~1 GB ceiling stands. Step 2a fixes the *number the gate
  reads*, which was wrong independently; it does not raise the ceiling.

## Tests

Rust `cargo test --lib`: `free_space_matches_df` asserts the command against
`df -k /` (byte match, a 64 MiB drift allowed for the gap between the two
calls — enough to catch a wrong struct layout, which is off by gigabytes) and
`free_space_rejects_a_bad_path` covers the error path. Vitest 98; the storage
interface spec still passes on both engines (browser → `quota` fallback).
