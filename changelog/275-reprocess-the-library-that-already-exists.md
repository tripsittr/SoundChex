# 275 — Reprocess the library that already exists

**Merged** 2026-10-06 · **Issues** #470

The point of the whole plan, and the riskiest single operation in it. Every fix
in phases 1–7 applies only to files the pipeline touches, and 8,330 items were
catalogued and filed by the old rules.

**Built to be run, not run.** The commands exist, are tested, and have been
exercised on this machine as far as it can exercise them — the library's media
lives elsewhere, so a5 runs the real thing.

## Three commands, in the plan's order

```
php artisan db:backup
php artisan library:manifest                       # path, size, hash of every file
php artisan library:reprocess --dry-run            # read this before the next step
php artisan library:reprocess --identify
#   open /admin/review and clear what it opened
php artisan library:reprocess --file --batch=500
php artisan library:verify-manifest <the manifest>
```

Running `library:reprocess` with no stage **refuses** and prints that list. A
single button over 8,330 items is the thing this must not be.

## `library:manifest`

Path, size and hash for every file, written **outside `storage/`** by default —
a manifest inside the tree it describes is no use once something has gone wrong
with that tree. A flat file rather than a table, because the point is to
survive the database.

A file already absent is recorded as `MISSING` rather than omitted, so a
pre-existing problem can never look like one the reprocess caused.

## `library:verify-manifest`

The plan's acceptance test as a command: *every file exists at its old or new
path with the same hash, or is in trash with a journal row.*

Five outcomes, and the distinctions are the value:

| outcome | meaning |
|---|---|
| **intact** | still where it was |
| **moved** | new path, same bytes — expected |
| **trashed** | gone but recoverable, with a journal row proving it was deliberate |
| **changed** | present, different bytes — a re-tag, or corruption |
| **lost** | nowhere, with nothing recording a move. **The only problem.** |

Exits non-zero when anything is lost or changed, so it can gate a batch in a
script — the plan says verify after each batch of 500, and a check nobody acts
on is not a check.

## `library:reprocess`

Staged, because the staging *is* the safety. Identification is reversible — a
wrong field can be re-fetched. Filing moves bytes. Doing both in one pass would
mean a bad match became a misfiled file before anybody could look, so
`--identify --file` together is refused.

**It refuses to run while the queue has work.** Two processes advancing the same
item's stage will disagree about where it is, and `Handoff.md` is explicit that
the bundled runtime holds the database open. `--force` exists because the check
is a heuristic — a worker on another machine is invisible — with the risk
stated. This caught a real condition on the first run: 24 jobs were queued from
the version backfill.

**Two kinds of item are skipped:**

- one a person has **judged** (`reviewed_at` set) — dragging it back is the
  S-302 trap, and doing it to 8,000 items at once would undo every review ever
  made
- one with an **open question** — it is waiting on an answer, and re-running the
  stage would discard the evidence the reviewer is looking at

Filing sends items to **`Planned`**, not `Filed`: the plan stage computes a
target and journals it before anything moves, which is what makes the move
reversible.

## Worth knowing

- **`--limit` needed fixing twice.** `lazyById()` re-chunks by id and
  **discards `limit()`**, so a run asked for 8 records wrote 8,330. Counted by
  hand in both commands, with a test.
- The dry run reports **"files that would move: 0"** on this Mac. That is
  accurate, not broken — only 3 of 400 items have a readable file here, because
  the media is elsewhere. It is exactly why a5 has to run this.
- Nothing has been reprocessed. The trash is not purged and batches stay
  undoable for 30 days, per the plan's step 6.
