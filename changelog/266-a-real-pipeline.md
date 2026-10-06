# 266 — A real pipeline

**Merged** 2026-10-06 · **Issues** #465

Enrichment was one job that identified, tidied, normalised, embedded a cover
and filed the file inside one `handle()`. A failure anywhere marked the whole
item `failed`, a success anywhere could not be resumed from, and nothing
recorded which step an item had reached.

The guarantee this delivers: **no item stays invisible and idle.** Every file
ends either filed, or waiting for a person with a reason recorded.

## What changed

### Ten stages, one job class, five outcomes

`catalogued → probed → hashed → identified → enriched → deduped → checked →
planned → filed → published`, each a `Stage` with one method, each idempotent,
each on the queue its work belongs to (`io` moves bytes, `cpu` reads files,
`net` talks to other people's servers).

Every stage returns exactly one of `Done`, `Skipped`, `NeedsReview`, `Retry` or
`Failed` — and **never `null`**. That was the third structural fault in the
audit: "nothing to do", "failed", "needs a person" and "try again later" were
one value, which is how the organizer reported 85 successes and one silent
failure, and how a provider being down read as "no match".

`PipelineRunner` owns every transition, so `processing_status` (what clients
read), the stage, the state, the attempt count and the timestamp cannot drift
apart. Four columns written in four places is how they drifted before.

### `pipeline_state = waiting` is the fix for "lands nowhere"

The audit listed seven ways to end up hidden from the library *and* absent from
review: a fuzzy music match, an organizer move failure, `file_missing`, an
unreadable file, a stuck `pending`, a provider outage, any quality problem.

A parked item is now visibly waiting, with a reason on the row, and the sweeper
leaves it alone — re-queueing an item that is waiting for a person would undo
their pending decision and spin forever.

### One entry point

`LibraryIngest::accept()` is the only way a file becomes a row. The scanner,
its recovery path and `library:import-music` all call it; the CSV import marks
its rows complete explicitly, because a CSV row describes a *record* with no
file and so has nothing to probe, hash or move.

The row and its first stage are created **in one transaction**, which closes
the window where a crash between the two left a row hidden with nothing to find
it: `ResolvedScope` hides anything that is not `complete`.

`library:import-music` previously created its own rows and so skipped the hash,
the duplicate check, the intake history, the catalogued event and local
artwork. That is why an imported track behaved differently from a scanned one.

### A failure is no longer indistinguishable from "nothing to do"

`accept()` returns `null` for "already catalogued" — an ordinary answer on a
rescan — and throws `IngestFailed` when something actually went wrong. Folding
both into `null` meant a dropped file looked exactly like a file that needed
nothing, which is the rule-2 mistake. Found while driving a real file through
the pipeline, not in review: a `NOT NULL` violation on `user_id` was being
swallowed and reported as "already catalogued".

The scanner catches it per file, so one bad file does not end a scan of ten
thousand.

### Duplicate detection moved to after identification

It ran inside the scan loop, before any metadata existed, so the ISRC,
MusicBrainz, AcoustID, TMDB and episode passes had nothing to compare — only
byte-identical copies were ever found at import, which is the minority of real
duplication. A second rip of a film never matches byte for byte.

### Every file move is journalled before it happens

`file_moves` records the intended move in `planned`, flips to `started`
immediately before the filesystem call, and to `done` after — with the source's
device, inode, size and hash captured **beforehand**.

That closes the organizer's crash window. Previously `rename()` could succeed
and the process die before the row was saved: the item still pointed at the old
path, the sweep called it `file_missing`, and the next scan catalogued the moved
file as a *new* item — the same file in the library twice, with nothing
recording that one move had happened.

`FileMoveJournal::reconcile()` resolves anything left mid-move, and the four
cases are each decidable from what was recorded: target present and source
gone (finish the bookkeeping), source present and target absent (replan), both
present and the same file (finish), both present and different (**leave it** —
a person has to look, and guessing deletes one of them).

`library:undo-moves` reverses completed moves by `--batch`, `--item` or
`--since`, newest first so a file moved twice lands back at its original path.
It refuses without a scope: "reverse every move ever recorded" should not be one
keystroke away.

### The sweeper, and the end of the single-worker hack

`library:pipeline-sweep` runs every five minutes and handles five things:
stages stalled past their own timeout, items marked queued with no job behind
them, failed stages with attempts left, moves left mid-flight, and **items
hidden with no stage at all** — which it adopts at the first stage rather than
guessing a later one.

This replaces `release_reservations_on_worker_start`, which was only safe with
exactly one worker: it released jobs other workers were *actively running*, so
a second worker meant the same job ran twice. It now defaults off. Requeueing
by stage timeout is safe with any number, because every stage is idempotent and
`claim()` is an atomic conditional update.

### The guarantee is checked, not believed

`server:health` gains `stranded_items` (must be zero) and `unfinished_moves`.
An item hidden with no stage is invisible to every other report, so the number
that matters most had nothing watching it.

## Worth knowing

- **Migration included and run on the Mac.** All 8,326 rows were backfilled by
  status: 8,246 `published/done`, 80 `identified/waiting`, **zero stranded**.
  Backfilled rows get a null `pipeline_updated_at` deliberately — stamping them
  `now()` would make every stuck item look freshly updated for one timeout
  window.
- **`QUEUE_RELEASE_RESERVATIONS_ON_START` now defaults to `false`.** Set it
  true to keep the old behaviour for non-pipeline jobs on a single-worker
  install. `StrandedJobsTest` enables it explicitly rather than relying on a
  default it used to get for free.
- **Three queues exist but the bundled supervisor still runs one worker on the
  default queue.** Until the supervisor config names `io`, `cpu` and `net`, work
  on those queues will not be picked up — the supervisor change is part of
  this phase's deployment, not its code.
- The probe and quality stages are deliberate pass-throughs until #467. They
  return `Skipped` with a reason rather than `Done`, so an item's history does
  not claim work that did not happen.
- Verified end-to-end against the real library: a file flowed
  catalogued → probed → hashed → identified and then **parked with a reason**
  rather than vanishing. Stranded count stayed zero.
