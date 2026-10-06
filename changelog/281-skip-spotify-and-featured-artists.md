# Skip, Spotify's dead endpoint, and featured artists

Three faults reported together from one review-queue item.

## "When I try to skip them they come back to the top"

`skip()` called `next()` and **wrote nothing**. The queue is ordered by
`duplicate_detected_at` then `id`, neither of which a skip touched, so the item
stayed exactly where it was — through a reload, a re-search, and the next day.
The button looked broken because it did nothing.

A skip now records a **7-day snooze**, and it is deliberately *not* a
resolution: the question stays open, still counts toward
`hiddenWithNothingOpen()`, and comes back in a week. Marking it answered would
hide a real question forever, which is the failure this rebuild exists to end.

**The snooze lives on the item, not only the review row.** Measured on the real
library: **86 of 124** items in the identify queue have *no review row at all* —
the queue selects on columns of `media_items`, and only items the backfill
reached carry a `review_items` row. Snoozing the row alone would have worked for
38 of them and silently done nothing for the other 86, which is the same bug in
a new place. Caught by running the fix against live data before writing a test
for it.

## "It also says Spotify errored"

Spotify **withdrew `/v1/audio-features`** in November 2024 for apps registered
after that date. Verified against this install's own working app: the token
request and `/v1/search` both return **200**, and audio-features returns **403**
for a track id search had just handed back.

So every music item reported *"these sources errored: Spotify"* — on a source
that otherwise worked, for data that is cosmetic. bpm, energy, key and scale name
no recording and decide where no file goes. A review queue that cries wolf about
an optional extra teaches people to ignore it.

A 403 or 404 is now "not available to this app" and reported as nothing. A 500
still surfaces, because a real outage is worth knowing. The **track id is saved
before** the features call, so a refusal no longer throws away a successful
search.

## "It finds nothing" — and other sources should have

The deeper fault. `NYE [Feat Suki Waterhouse]` by `Local Natives, Suki
Waterhouse` matched **nothing**; `NYE` by `Local Natives` scores **100**.
MusicBrainz keeps the guest in the *artist credit*, not the recording title, so a
filename's bracketed feature defeats the search outright. Only two variants were
tried and both kept the marker — the existing stripper only removes a `" - "`
suffix, and this title has none.

Featured-artist markers are now dropped **from the query** (never from the stored
title: the file says it and a person recognises it). Both the bracketed and
bracketless forms, with the primary artist as well as the full credit — verified
that only title *and* artist cleaned together produce the match, and that
variant 4 returns a real MBID at score 100.

**19 of 110** items in the live identify queue carry a feature marker; **241**
library-wide.

## Also found, not fixed here

Only **2 of 7** music sources actually run on this machine:

| source | state |
|---|---|
| MusicBrainz, iTunes | run |
| AcoustID | no key — and it is the only source that identifies audio *by sound* |
| Spotify | keys under a second spelling (fixed in #281, not merged) |
| Last.fm | `requiredSettings()` returns a bare list, so it reports `missing: 0` (fixed in #281) |
| Deezer | toggle off — legitimate |
| File tags | declines when the file is absent — legitimate |

AcoustID is the one worth having: it would identify exactly the files that defeat
a title search.

## Verified

- 367 passed across Review, MusicBrainz, Spotify, Duplicate, Pipeline and
  Library groups.
- 13 new tests. Checked by reverting each fix in turn: **3 of 5** skip tests,
  **2 of 4** MusicBrainz tests and **3 of 5** Spotify tests fail without their
  fix. The rest pass either way by design and guard the opposite case.
- The skip was exercised against the **live library**: queue 131 → 130, the item
  left, the next advanced, snoozed 6 days.

## One flake fixed on the way

`travel(8)->days()` persists for the rest of the process, and a later test
creating a file "eight days from now" failed on a *file-exists* assertion in the
keep-both test — pointing nowhere near the cause. Now `travelBack()` explicitly.
