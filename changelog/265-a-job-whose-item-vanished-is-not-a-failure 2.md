# 265 — A job whose item vanished is not a failure

**Merged** 2026-10-06 · **Issues** #479

A queued job whose media item had been deleted threw and landed in
`failed_jobs`, where it read as a broken pipeline rather than as work that had
become moot.

## What changed

`EnrichMediaItemJob` resolved its item with `findOrFail()`, which throws
`ModelNotFoundException` when the row is gone by the time the job runs. That is
an ordinary occurrence, not an error: duplicate resolution deletes rows, so a
duplicate merged away while its enrich job sat in the queue produces exactly
this. Six such failures were on the Windows server and three on the Mac.

Both now treat a missing item as nothing to do and return.

a5 raised this reviewing #270 and predicted it would not be the only job with
the pattern. It was right — `TranscodeMediaJob` had it too, and is fixed here
as well. Every other job in `app/Jobs/` was checked; those two were the only
ones.

### Clearing the rows already there

`queue:prune-vanished-failures` removes failures that predate the fix. It is
deliberately narrow: a row goes only when the exception is
`ModelNotFoundException`, the job is one of the two that had the bug, **and**
the item id in its payload still does not resolve. Anything else is left
alone — a failed job is evidence, and clearing evidence because it is untidy is
how a real problem gets lost.

`--dry-run` lists what it would remove.

## Worth knowing

- Run on the Mac: removed 3, left the 8 genuine `database is locked`
  failures untouched. **a5 still needs to run it** for its six.
- The fix must not turn every problem into a silent skip, so a third test
  asserts an item that *does* exist is still found and processed.
