# 026 — Files that can actually be placed

**Merged** 2026-08-23 · **Issues** S-106, GitHub #34

One fault found by a real 46.3 GB transfer. It cost individual files rather
than stopping the run, and was permanent for every file it hit.

## What changed

### The download handle is released before the file is moved

`fetch()` streams into a `.part` file with `sink()`, then moved it into place
with that handle still open. Windows refuses to move or reopen a file another
handle holds, so the download completed — every byte arrived — and the
placement failed with `Permission denied`.

The name was then poisoned. The `.part` stayed behind, and because a retry
resumes from `filesize($temporary)`, the next attempt asked for a range
starting past the end of the file and was refused. **That file could never
arrive.** Five had failed and thirteen `.part` files were on disk when this
was found, the count growing as the run went on.

`importDatabase()` already released the handle for the catalogue archive.
`fetch()` is the same bug one layer down.

### A `.part` that is already complete is placed rather than re-fetched

The fix above stops new ones. This recovers the ones already stranded: if the
`.part` matches the expected size, it is verified and placed instead of
re-requested.

## Worth knowing

- No migration.
- Existing stranded `.part` files are recovered on the next attempt of that
  item — nothing needs deleting by hand.
- The first test was written badly and caught: it drove `verifyAndPlace()`
  directly and passed with the fix removed, because that method was never
  broken. It now drives `fetch()` against a fake that answers `416` — what a
  real server returns for a range past the end — so re-fetching fails it.

## The suite does not cover the sink release

Worth stating plainly so nobody later reads a green run as proof of it.

`Http::fake()` writes its sinks with `file_put_contents()`, which closes the
handle immediately — so the open-handle condition this fixes cannot occur under
test. Removing `releaseSink($response)` from `fetch()` leaves the whole suite
green on both machines.

The evidence for it is a probe on real data, not a test:

```
File.Exists       : true      Directory listing : present, 6,593,713 bytes
PHP is_file()     : false     File.Open         : UnauthorizedAccessException
```

The file downloaded completely and could not be placed. The complete-`.part`
recovery beside it *is* covered — removing that one turns a test red.

## What was dropped before merging

A third change added the `\\?\` prefix so Windows could open paths over
`MAX_PATH`. It was written on a report that 37 files would fail on open — and
that report was withdrawn after being tested rather than measured. Long paths
already work on the receiving machine: a 416-character path opens fine there.

Worse, the prefix **broke paths that worked**. PHP does not resolve `\\?\`
and treats it as part of the filename, so five tests failed on Windows,
including four core file-transfer ones that pass on `main`. It was invisible
here because `longPath()` is a no-op off Windows — the exact asymmetry the
review gate exists to catch.
