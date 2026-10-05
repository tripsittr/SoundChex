# The two SQLite settings that look fine and break the queue

Both of these failed in production while reading as correct, and neither is
findable by looking at the code that failed.

## transaction_mode must be IMMEDIATE

`"database is locked"` was the commonest failed job here, and
`busy_timeout = 120000` did nothing for it. The stack trace pointed inside the
framework:

    DatabaseQueue::pop() -> transaction() -> markJobAsReserved()
      update "jobs" set reserved_at = ...   <- BUSY

`transaction_mode` was `DEFERRED`, the Laravel skeleton default. A deferred
transaction takes no lock at `BEGIN`: the select fixes a read snapshot, and the
update then asks to become a writer. If anything committed in between, SQLite
refuses the upgrade **immediately** — waiting cannot make an already-taken read
valid, so `busy_timeout` is never consulted at all.

`IMMEDIATE` takes the write lock at `BEGIN`, so there is no upgrade to refuse
and contention becomes something the timeout can wait out. Honoured on PHP 8.4
and above. Write transactions serialise as a result, which is the right trade
for one machine.

## retry_after must exceed the worker's --timeout

`retry_after` was 90s while seven jobs declared timeouts above it, up to 7200s,
and transcoding and transfers have no ceiling but the worker's `--timeout`
(21900). The queue treated a still-running job as abandoned, handed it to
another worker, and with `tries = 1` the second copy failed instantly as
`MaxAttemptsExceededException` — while the first was still working.

A test reads the worker timeout out of `src-tauri/src/supervisor.rs` rather than
repeating the number, because a number copied into a test asserts only that
someone once copied it correctly.

## The consequence of raising it

A reserved row does not record *which* worker holds it, so a worker killed
mid-job leaves its job reserved and nothing reconsiders it until `retry_after` —
now six hours. `AppServiceProvider` frees reservations on `WorkerStarting`,
which is safe only because the supervisor runs exactly one worker, and is behind
`queue.release_reservations_on_worker_start` for anyone running more.
