# 011 — The catalogue arrives, and cannot be put down

**Merged** pending · **Issues** S-88

Fourth failure of the same transfer, and the furthest it has got. The catalogue
downloaded, decompressed and passed its header check — then:

```
Could not put the new catalogue in place.
```

## What changed

### The swap was written for POSIX

The last step is `rename($staged, $target)`. On Windows a rename over a file
another process holds open fails with `Access is denied (code: 5)`. Reproduced
directly: hold a read handle on the target, rename over it, watch it fail;
close the handle, watch it succeed.

Five processes hold `database.sqlite` open on this machine — `artisan serve`
(three), `queue:work`, `schedule:work` — and **the one doing the rename is the
queue worker itself**, through its own database connection. So it could not
succeed even with everything else stopped.

Two routes now. The connection is dropped first, so this process stops being
one of the things in the way, and `rename()` is tried — it is atomic and it is
the right answer whenever it can be had. When it still fails, the contents are
written over the existing file instead, which Windows does permit. That is not
atomic, which is exactly why it is second: an interrupted write leaves a
catalogue that is part old and part new, and the only thing behind that is the
backup taken before any of it. A short write is reported as a failure rather
than a success.

The disconnect is narrow on purpose. It happens only when the live connection
really is the file being replaced, read from the connection rather than from
`config()` — the two differ whenever something has pointed config elsewhere,
and disconnecting an `:memory:` connection destroys the database rather than
releasing a handle on it. The first attempt at this took out 18 tests.

### The backup was missing the most recent writes

`backupExisting()` copied `database.sqlite`. In WAL mode the recent writes are
in `database.sqlite-wal` — **4.3 MB of them** on this machine — so the backup
taken immediately before replacing an irreplaceable catalogue was short of
exactly the part least likely to exist anywhere else. It is checkpointed first
now.

### The old write-ahead log no longer outlives the catalogue

A stale `-wal` and `-shm` belong to the database that was just replaced.
SQLite should reject a mismatched log, but several megabytes of another
database's pending writes sitting beside a fresh file is not something to leave
and hope is ignored.

### A catalogue that cannot be placed is kept

It used to be deleted on failure, which meant fetching the whole thing again to
retry a rename. What arrived was correct — it downloaded, unpacked and verified;
it is the swap that failed. It stays as `database.sqlite.incoming`, and the
error says so.

## Worth knowing

- **Everything using the catalogue must be restarted after a successful
  transfer.** Processes holding the old file keep reading the old file, and on
  the fallback path they are reading a file that changed underneath them. This
  is logged at the moment it happens.
- Both routes are exercised: one test holds the target open so the rename is
  refused and the fallback runs.

## Tests

**406 PHP · 87 Vitest.** 3 new, all passing.

Removing the fallback turns the held-open test red. Leaving the `-wal` behind
turns the sidecar test red.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86 — `/` against `\` on Windows and one macOS-only log path.

**Playwright was not run** here; no `.env.e2e` on this machine. Nothing in this
change reaches a browser.

**What is unverified:** the fallback path is proven against a file held open by
a test, not against a live catalogue with five processes and an active WAL on
it. Whether those processes tolerate the file changing underneath them is
exactly why the restart above is not optional.
