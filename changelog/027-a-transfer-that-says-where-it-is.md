# 027 — A transfer that says where it is

**Merged** 2026-08-23 · **Issues** S-108, GitHub #34, #38

The machine doing a copy is the only one that knows how far it has got, and
the machine being copied had no way to ask. So both sides guessed, and both
were wrong within an hour of each other.

## What changed

### The receiver reports, the source displays

`POST /api/v1/transfer/progress`, on the transfer token. Which transfer it
refers to comes from the token rather than the body, the same rule as
`requests/mine` — a receiver can only report its own.

`RunTransferJob` already reschedules itself every ten seconds, so the report
goes out on each pass and costs nothing extra.

The "Currently allowed" section of the transfer page shows files, bytes and
failures, and — more usefully — **when the last report arrived**. A report
that stopped coming is the thing neither machine could previously see.

### What landed, not what left

`bytes_complete` is summed from items that reached `complete`, and an item only
reaches `complete` after its rename succeeds. Bytes on the wire were the
misleading number in every false alarm: a file can transfer in full, byte for
byte, and still fail to be placed — which is exactly what the Windows
open-handle bug did all morning.

## Worth knowing

- **A migration adds ten nullable columns to `transfer_requests`.** Nothing is
  dropped or rewritten, and it has not been run against the production
  database on `macbookair` — that needs the owner's say-so.
- Reporting is best-effort. Every failure is logged and swallowed: a 46 GB copy
  should not stop over a status update.
- Nothing here is trusted beyond display. A receiver could report whatever it
  liked; the worst it can do is lie about its own progress.
- A `worker_alive` field was written and then removed in review: only a running
  worker can report, so it could only ever be `true`, and `progress_at` going
  quiet already carries that signal honestly.
