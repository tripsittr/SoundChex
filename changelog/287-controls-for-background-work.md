# Controls for background work

Tracker #508. Reported: the dashboard could say **"7,018 waiting, 6 failed"** and
offer nothing to do about it.

## Pause and Resume

Implemented as a flag the worker reads through `Queue::looping()`, **not**
`queue:restart`. Restart kills the process and the LaunchAgent starts it straight
back up — that is a restart, not a pause.

Returning false from `looping` stops the worker *reserving* the next job while
leaving the process alive. Nothing in flight is interrupted: a transcode
half-way through finishes, because this runs between jobs rather than during
one. The pause lapses after 24 hours, so a forgotten one cannot wedge the queue.

## Discard queued, and Clear failed

Both confirmed, and both say what is about to happen.

**Discard** leaves alone anything a worker has in hand — `reserved_at` means the
bytes may already be moving, and deleting the row would not stop that, only lose
the record of it.

**Clear failed** names what is being lost (`6 x EnrichMediaItemJob`) and states
plainly that those rows are a log rather than work: clearing retries nothing and
cancels nothing.

## More to read

A **measured** throughput and ETA — sampled from the pending count, not
estimated. A rate-limited source like MusicBrainz at one request a second is
nothing a static guess would capture. Null until there are two samples, because
one number is not a rate and a made-up figure is worse than an empty column.

A **Share** column showing which job the backlog actually is. With one kind at
6,398 of 6,404 the raw number reads as "the queue is busy"; the share says "it is
this one".

## Two bugs found while building it

**The header block had never rendered.** The widget used a slot called
`headerEnd`, which does not exist on Filament's section component — so the whole
block was silently dropped, including the pre-existing **Retry failed** button.
It has never appeared once. The slot is `afterHeader`.

**The ETA was always null.** `throughput()` wrote a new sample on every call, so
the second call in one render compared against a sample zero seconds old and
returned null — and `minutesRemaining()` asks for the rate again. Memoised per
request.

A third was found by the tests: the cache guard sat in the provider hook, so
every *other* caller of `isPaused()` was unprotected. Moved into the service.

## Verified

- **104 tests pass** across the queue suite; 12 new.
- Each fix checked by reverting it: the per-request memo and the cache guard both
  fail their tests when removed.
- Exercised against the **live queue** (6,300+ real jobs): pause took effect, the
  button swapped to Resume, resume cleared it, and throughput measured **30
  jobs/min with 212 minutes remaining**.
