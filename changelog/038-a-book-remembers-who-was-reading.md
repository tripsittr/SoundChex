# 038 — A book remembers who was reading it

**Merged** 2026-09-12 · **Issues** S-134, S-135, S-136

A review of whether failures are actually being written down, as the working
agreement requires. Most of the app was already good at this. The reader was
not — and following that thread turned up two bugs underneath it that had
nothing to do with logging.

## What changed

### Two people can keep their own place in the same book

`reading_progress` was written before profiles existed. When profiles arrived,
two things were missed, and together they hid each other:

- `profile_id` was never added to the model's `$fillable`. `updateOrCreate()`
  keys the row on the profile, but mass assignment silently dropped it on
  insert — so **every row was written with a null profile**, whoever was
  reading. The whole household shared one place in every book, which does not
  look like a bug. It looks like the app forgetting where you were.
- The unique index still said `(media_item_id, user_id)`. A household shares
  one login, so the moment the first fault was fixed, the second person to open
  a book had their save **refused by the database with a 500**.

Both are fixed, and the index now matches how the rows are actually addressed.
Rows written before profiles existed keep a null `profile_id` and are still
found by account, so nobody's existing position is lost.

### A book read offline no longer loses its page

The reader's progress save swallowed its failures:

```js
}).catch(() => {
    // Losing one position update isn't worth interrupting reading.
});
```

True of one update. Wrong about a whole session read offline, which is every
update. The player has queued its writes for a while — the position of a film
someone is watching on a train — and a book read on the same train was dropping
them instead.

Reader writes now go into the same queue, keyed per item so a long session
leaves one entry rather than one per page turn. Because a queued write arrives
late, the endpoint gained the staleness guard the media endpoint already had: a
position recorded before what the server already holds is refused rather than
applied, so reconnecting a phone cannot rewind reading done elsewhere.

### Failures that were invisible now say what and which

- **The player silently streaming a downloaded track.** If reading a local file
  threw, the player fell back to the network with nothing recorded — the user
  downloaded the track and is now on mobile data, and no evidence says why.
- **Files that could not be filed into the library.** Both `EnrichMediaItemJob`
  and `TranscodeMediaJob` caught a failed move and called `report($e)`, which
  gives a stack trace and no item id. A full disk or an unplugged drive fails
  every item in a scan identically, and the question afterwards is always
  "which files are still in the inbox?". Now logged with the item, title and
  path.

## Worth knowing

- **The migration has been run on this machine.**
  `2026_09_12_100000_fix_reading_progress_unique_index` swapped the unique index
  in 31ms against the live library, after a `.backup` of the database. Verified
  afterwards: the old index is gone, both new ones are present, and both
  existing rows survived unchanged. It refuses to run if it finds duplicate
  `(media_item_id, profile_id)` pairs rather than failing halfway through
  creating the index. **It still needs running anywhere else the app is
  deployed.**
- **Your live database has 2 reading-progress rows, both with a null profile.**
  That is the bug's fingerprint. They stay readable — the fallback path finds
  rows by account — but they are not attributed to anyone, and cannot be now.
- `down()` will fail once two profiles have a position in the same book. That
  is deliberate: restoring the old index means destroying one person's place,
  and refusing is better than picking a reader to forget.

## Found while verifying this, not fixed

**11 of your 14 books cannot be opened.** `media_items.file_path` is absolute
for 1,306 rows, and 1,303 of those still begin
`/Users/tripsittr/Documents/GitHub/SoundChex/...` — where the repo used to live.
`ReaderController@show` aborts 404 when the file is missing, so those books 404.

The files are fine: 400 of 400 sampled exist at the current location. It is a
database-only correction with nothing to move on disk. Logged as **S-137**
rather than fixed here, because it is a bulk rewrite of paths to real files and
deserves its own change with a dry run — and because whether they should become
new absolute paths or *relative* ones, like the other 7,032 rows, is a decision
worth making deliberately rather than in passing.

## Still wrong

- The two existing rows cannot be attributed retroactively. Whoever read those
  books, the database never recorded it.
- Logging was reviewed, not instrumented exhaustively. Health probes, private
  browsing storage failures and expected-offline paths are still deliberately
  silent, per the rule's own exemption — making them noisy buries the lines
  that matter.
- The reader queue is covered by feature tests against the endpoint. The
  browser half — a genuine offline read, then reconnecting — is not covered by
  an e2e test yet.

## Tests

**PHP 532** (six new), **Vitest 98**, **Playwright 306**.

The reading-progress tests are what found both bugs: written to cover the new
staleness guard, they failed with a 500 that had nothing to do with it. Each of
the three fixes was verified to turn a different test red when reverted.
