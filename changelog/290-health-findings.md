# The health check reported a problem nobody could clear

Tracker #509 and #510, both found by running the merged work on the live install
rather than trusting the tests.

## Nothing maintained the invariant it asserted

`server:health` checks hourly that no item is hidden from the library without an
open review item saying why. `library:backfill-review` is what restores that —
and **nothing scheduled it**. It ran only when somebody typed it.

Across one session, as enrichment produced fuzzy matches, the count went **8 →
94 → 377 → 611**, with health reporting "unhealthy" every hour and the remedy
sitting behind a command the owner had no reason to know existed. A dashboard
that reports a problem nobody can clear teaches people to ignore the dashboard.

It now runs **alongside the pipeline sweep**, every five minutes, with a
`--quiet-ok` flag so a clean run writes nothing. The two fix opposite halves of
one guarantee: the sweep parks an item, this explains why.

## And the count could never reach zero anyway

The check asked for anything that was not `complete` — which includes an item
the worker is part-way through. `EnrichMediaItemJob` sets `needs_review` and
*then* dispatches the event that opens the review row, so there is a window where
both are true.

Sampling the live server ten times in three seconds showed the offending id
**climbing with the queue** — 3020, 3021, 3022 — and each one reading as
`complete` by the time it was inspected individually. So while anything drained,
health reported one unexplained item permanently, for a different item each time.

A number that never reaches zero is one nobody reads, which would hide the real
stuck item the check exists to surface. Only the **parked** statuses count now:
`needs_review` and `failed`. `processing` is work in progress by definition and
`pending` is waiting its turn.

## Verified

- **157 tests pass** across the review, health, backfill and pipeline suites;
  8 new.
- Each fix checked by reverting it: the two status tests fail against the old
  predicate, and two schedule tests fail when the entry is removed.
- On the **live install**: `server:health` went from
  `611 hidden item(s) with nothing saying why` to reporting only the queue
  backlog, which is real work draining.

## A correction to my own report

I first described the leftover count as a stuck item, then as transient, before
the sampling settled it. The id climbing with the queue is the evidence; a single
row read twice looked like either.
