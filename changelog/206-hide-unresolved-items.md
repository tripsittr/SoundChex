# The library shows only what is certain

S-396, and S-389 with it.

An owner rule: anything that is not certain, or is waiting for someone to look
at it, is hidden from the library until that is settled. It stays in the
database and in the admin panel — hidden, not deleted, so a re-scan or a review
brings it back with its plays, playlists and progress intact.

Before this, `processing_status = needs_review` existed and 83 items carried it,
but nothing filtered on it. Those items were served to every client and appeared
in browse, search, shuffle and the play queue.

## Why a global scope

`ContentGate` looked like the place for it: its own comment says adding a gate
to eight query sites is how it ends up applied in seven. That warning is exactly
why the filter is not there. An audit of every read path found **nine
user-facing queries that never reach `ContentGate`** — the "more like this"
rail, the watchlist rail, the nav counts, the genre counts, `albumQueue()`, the
playlist cover mosaics and three Blade tile lookups — plus every route-model
bound `MediaItem $item`, which is how streaming, reading, subtitles and progress
resolve theirs.

A global scope is the only construct that covers a `findOrFail` in a route
binding. Anywhere else would leave a hidden item unlistable but still streamable
by id, which is not hidden at all.

The trade is that everything which legitimately needs to see unresolved items
must now say so, via `MediaItem::unresolved()`. Forgetting one empties an admin
screen rather than leaking data — the safer direction for the mistake to run.

## Missing files (S-389)

Two rows pointed at files that were gone from disk. The rows looked healthy —
path set, not duplicates, processing complete — so they listed and queued
normally and then failed at the moment of playing.

Whether a file exists is a disk question and cannot be asked in SQL, so the
scanner answers it and writes `file_missing`, and every read path filters on the
column. A sweep at the end of each scan marks what has gone and unmarks what has
come back.

The sweep is deliberately cautious about volumes: if the item's whole directory
tree is absent — an unplugged drive, a share that did not mount — it marks
nothing. Otherwise one loose cable would empty the library and the next scan
would have to undo all of it.

Run against the real library: exactly the two known rows, out of 8,326.

## Three bugs this turned up

- **Every episode created its own series row.** `attachToSeries()` used a scoped
  `firstOrCreate`, so it never found the `pending` series row it had just made.
- **Enrichment would have thrown on every item it touched.** The job sets an
  item to `processing` — hiding it — and then calls `refresh()` eight times.
  `refresh()` and `fresh()` now re-read unscoped, because an item must always be
  able to re-read itself.
- **The Needs Review badge read zero.** It counted through `$model::query()`,
  which the scope filtered — a count of exactly the items being hidden.

## Also

- **The "Needs Review" rail is gone from the browse page.** It existed to show
  unresolved items to the user, which is the opposite of the rule. Unresolved
  items belong in the admin panel, which already has a tab and a widget for
  them. The two now-unreachable "Review" badges in the Blade views went with it.
- **Playlists report `unavailable_count`.** A hidden track otherwise vanishes
  with no gap and a twelve-track playlist just reads as eleven. A rating cap is
  permanent and silence suits it; an unresolved track is temporarily absent, and
  silence there reads as data loss. Counted off the pivot, because the relation
  carries the same scope that hid the track.
- **The test suite sets its own memory limit** (S-382). It needed more than
  PHP's 128M default and died partway through rendering a Blade view, which
  reads like a broken test and is not one. In `phpunit.xml` rather than php.ini,
  so the suite carries its own requirement and passes on a fresh machine.

## What this cost in tests

161 tests failed on the first run. Items created without an explicit status take
the column default, `pending`, and were therefore hidden — in production nothing
stays pending, but in a test there is no enrichment. Test fixtures now default
to `complete` via a `creating` hook in the base `TestCase`, and the tests that
care about an unresolved item set the status explicitly, which reads better than
inheriting it from a column default.

1,047 passing, 0 failures.

## Known gaps

- `file_missing` is only as fresh as the last scan. A file deleted an hour ago
  stays listed until the next one.
- The clients do not yet show `unavailable_count` — the API reports it, the iOS
  and web playlist views still say nothing.
- Nothing surfaces "this is hidden because…" to the owner outside the admin
  panel.
