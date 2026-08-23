# 007 — Cancelling a transfer, and the failure that made every retry fail

**Merged** pending

The first real transfer between two machines failed three times, each for a
different reason. This is the third, plus the two buttons the failures made
obvious were missing.

## What changed

### A failed download no longer poisons every transfer after it

Transfer 6 could not begin at all:

```
fopen(storage/app/transfer-incoming.sqlite.gz): Failed to open stream: Permission denied
```

It had not contacted the source. It failed writing the file it downloads into,
on its own disk, in a directory it can write to.

The archive is unlinked while the response still holds the sink file open. On
Windows that does not remove the name — it leaves it **delete-pending**, and
every later open of that name is refused until the process holding it exits.
The queue worker is long-running, so the name stayed poisoned and every
subsequent transfer failed the same way before it started.

Reproduced directly on the machine rather than reasoned about: open a handle,
unlink, reopen, and the reopen fails with exactly that message; close the
handle, and it works.

Fixed twice over, because either alone would do and both are cheap. The sink is
closed explicitly before the archive is touched, and the archive is named per
transfer rather than shared, so one poisoned name cannot block a transfer that
had nothing to do with it.

### A failure says what the server said

`The catalogue could not be read (500).` was all that survived the failure in
006 — the source had explained itself in the response body, and the receiver
kept the number and discarded the sentence. Diagnosing it meant reading the
other machine's log, which is the thing rule 7 exists to prevent.

The body goes to the sink rather than into memory, so it is read back bounded
and both recorded and logged. A permission failure on the local disk now says
what it is instead of reading as a network problem.

### Cancel, and delete

**Cancel** stops a transfer here *and* on the machine being copied. Pausing —
which is what the button beside it does — leaves the request approved and the
token live over there, so a transfer paused and forgotten leaves another server
able to read this one until the token lapses.

Which request is cancelled comes from the token, never the URL. A receiver
holds a token for exactly one request, so it can end that one and no other;
otherwise cancelling would be a way to stop somebody else's transfer by
guessing an id, and the id is a small integer.

Stopped here first, reported there second. A source that is asleep must not
leave this machine still transferring.

**Delete** clears a finished transfer from the list, taking its items and its
part-downloaded catalogue with it. Refused while one is running, because the
row is what queued file jobs read to decide whether to carry on — clearing the
list should not be a way to abandon a transfer half way.

### Two tests that could not fail

The test written for 006 re-implemented the compression instead of calling it.
It asserted that gzip works, which was never in doubt, and stayed green with
the fix reverted. The compression moved into `CatalogueArchive` so the test
runs the code the endpoint runs — putting `gzopen('php://output')` back now
fails three tests.

`test_it_refuses_anything_that_is_not_a_database` never reached the
`SQLite format 3` check it was named for. On `:memory:` the import stops at
"no database file to replace" first, so it took the same path as the test below
it and passed for the wrong reason. It now runs against a scratch catalogue and
a scratch storage root, both created and removed by the test, with Eloquent
left on `:memory:` so the harness is untouched.

## Worth knowing

- **`scripts/auto-update-main.ps1` switches the working tree to `main`** every
  five minutes if it finds it on another branch. It moved this work off its
  branch mid-session. It also skips its pull whenever a tracked file is dirty,
  so this machine sat two commits stale for an afternoon on one edit to
  `Issues.md`. Worth knowing before doing branch work here.
- **There were two `S-74`s.** One arrived from each machine. The duplicate is
  renumbered `S-78` and closed as a duplicate of `S-75` rather than deleted.

## Tests

**379 PHP · 87 Vitest.** 45 of the PHP tests cover transfers; all pass.

Every guard added here was confirmed to fail with the guard removed —
`php://output` restored, the `SQLite format 3` check deleted, the per-transfer
archive name reverted, the reason-capture removed, and the running-transfer
delete refusal removed. Each turned its test red.

**Still failing, and not from this change:** 6 failures and 1 error, all
pre-existing on Windows and confirmed identical on an unmodified tree —
`LibraryOrganizer` and `LibraryScanner` compare `/` against `\` in stored
paths, `ConversionFiler` the same, and `HostServicesTest` writes to a macOS log
path. They deserve an issue of their own; they are not touched here.

**Playwright was not run.** There is no `.env.e2e` on this machine, and
`bootstrap.sh` refuses without scratch paths — the guard that exists because a
hardcoded path destroyed the real library once. The spec for the two new
buttons is written and syntax-checked but **unverified**; it needs a machine
with the e2e environment configured.

**The delete-pending fix is verified by reproduction, not by the suite.**
`Http::fake()` writes sinks with `file_put_contents`, so it never holds a
handle open and cannot reproduce the failure. What the suite pins is the other
half — that each transfer downloads into its own file.
