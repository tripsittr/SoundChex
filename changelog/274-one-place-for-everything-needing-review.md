# 274 — One place for everything needing review

**Merged** 2026-10-06 · **Issues** #469

Review was a **status, not a record**. Five columns on `media_items` plus a
reports table, each added for one feature — so a new failure kind had nowhere
to go, and went nowhere. That is why the audit found **seven ways to end up
hidden from the library *and* absent from review at the same time**.

## The number that was wrong

Measured before writing anything:

```
96 items hidden from the library with nothing open to explain why
```

Absent from the library because `processing_status` was not `complete`, and
absent from review because nothing had flagged them. Invisible to every report.

**After the backfill: 0.**

## What changed

### `review_items`

One row per reason, carrying what a column cannot: *why*, *the evidence*,
*what to do about it*, and *what was decided*.

A **partial unique index** on `(media_item_id, reason) WHERE status = 'open'`
means re-running a stage updates the existing complaint rather than stacking a
second copy — which is how the old duplicate flag came to re-fire on every
sweep. Scoped to open rows, because a file can legitimately be reviewed for the
same reason twice over its life.

### `SystemReviewReason` — thirteen cases, kept separate

`ReviewReason` stays exactly as it is. Its five values are already shown in
every client, so adding thirteen system cases to it would break a contract the
apps depend on. `source` says which enum applies.

Each reason knows three things:

- **its job** on the review screen, because a screen offering four jobs is
  usable and one offering thirteen reasons is the taxonomy problem again (#480)
- **where to resume** — picking a candidate needs enriching again, accepting a
  quality finding only needs filing. That is the difference between a second of
  work and re-running the pipeline.
- **whether it hides the item.** Most do not: a loose match or a missing album
  is perfectly usable and should stay visible while somebody gets round to it.
  Hiding everything is what made 8,440 rows invisible.

### Resolving actually resolves

`ReviewLog::resolve()` closes the row **and** resumes the pipeline — but only
once nothing else is open for that item, because an item with a second
complaint is not ready to move on.

`dismiss()` is recorded differently from `resolve()`, because the distinction is
the user's answer: a dismissed cover warning means the cover was right all
along, a resolved one means they picked a different image.

### The review page reads the record

A recorded reason outranks anything derived — it was written at the moment the
decision was needed, with the evidence to hand. The columns remain the fallback
for anything predating the backfill.

### The health check asserts the invariant

`server:health` gains `unexplained_hidden_items`, which must be zero. The
guarantee is now checked continuously rather than believed.

## A bug the tests caught

`resolve()` closed every review item and **silently failed to resume the
pipeline**, leaving items parked forever. Cause: `ReviewItem::create()` returns
a model with no relations loaded, so `->mediaItem` was `null` and the resume
returned early. `dismiss()` failed to stamp `reviewed_at` the same way.

The item is now looked up explicitly, unscoped — anything in review is hidden
by `ResolvedScope` by definition, so a scoped lookup would find nothing exactly
when it matters.

## Worth knowing

- **The old columns are left in place and still written.** Clients read
  `processing_status`, and changing the storage and the readers in one release
  would be untestable. They come out once this has proven itself.
- `library:backfill-review` derives each reason from the columns that *were*
  set — reading history rather than guessing. Run here: 81 missing album, 12
  compilation matches, 3 missing files.
- MySQL has no partial index, so the one-open-per-reason rule is enforced in
  the application there. SQLite and Postgres get the index.
