# Per-job pause, and how many jobs run at once

Tracker #512. Reported: pause should be **per job**, with one job running at a
time by default and a setting to allow more.

## The install was running one worker, and always had

All three supervisors — the Rust one, the LaunchAgent, the systemd unit — each
start a single `queue:work`. On an eight-core machine with **4,366 jobs queued**
that is one core's worth of capacity and a backlog measured in hours. Changing it
meant editing three templates and reinstalling.

## Per-job pause

A **Pause / Resume** button on every row of the queue table.

Implemented with `Queue::before`, not `Queue::looping`. The `looping` hook runs
*before* a job is reserved and so has no idea which one is next — it can only
stop everything. `before()` has the job in hand, so a paused kind is **released
back to the queue** with a delay while every other kind keeps running.

Released rather than deleted: a pause is *"not now"*, and the work is still
wanted. The job rejoins and runs when the kind is resumed.

This matters because the reason to pause is almost always specific — enrichment
hammering a rate-limited API, or a transcode making the machine unusable.
Stopping everything also stops the cover fetches and the duplicate scan, which
were not the problem.

## One job at a time, configurable

A setting from **1 to 8**, defaulting to **1**.

One is right for most of this app's work: enrichment is limited by how fast
*other people's* services answer — MusicBrainz allows one request a second — so a
second job in parallel buys nothing there and doubles the chance of tripping a
limit. Hashing and transcoding are the exceptions, bound by this machine rather
than somebody else's, which is why it is a setting rather than a constant.

Capped at eight, because beyond that the disk is the bottleneck rather than the
queue, and an accidental 64 would make the machine unusable rather than fast.

A new **`queue:workers`** command manages the pool and re-reads the setting every
fifteen seconds, so changing the number takes effect **without a restart**. All
three supervisors now start it instead of `queue:work`.

Scaling down sends `SIGTERM`, which `queue:work` treats as *"finish the current
job and exit"* rather than *"die now"* — so a half-written transcode is never
abandoned to make a settings change land a few seconds sooner.

## A trap found while testing

`--once` returned **without stopping its children**, orphaning them. Nothing
would have reaped them, and the next start would have added a second pool on top
of the first. A test that leaves processes behind is worse than no test.

## Verified

- **114 tests pass** across the queue and supervisor suites; 8 new.
- The pool scales on the live machine: set to 3, started 3; `--once` now leaves
  the worker count exactly as it found it.
- The existing supervisor-agreement test still passes — it asserts `--queue=` is
  present in all three launchers regardless of the command name.

## Not verified here

The per-job release could not be observed end-to-end on this Mac: the running
worker is a LaunchAgent process started hours before this code existed, so it has
never loaded the hook. The state logic is tested; the release needs a restarted
worker to watch.
