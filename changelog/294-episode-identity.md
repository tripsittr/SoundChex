# An episode says what it is an episode of

Three fields the apps needed and the list API never sent.

**`series_title`** — an episode row carried its own number and title but
nothing naming the series, so a Continue Watching card could show "But at Last
Came a Knock" with no way to know it was Shameless. `parent_id` was already
sent, but resolving it meant having the parent loaded, and that shelf fetches
episodes without their series.

**`overview`** — the synopsis, from `notes`. The detail endpoint has exposed it
as `overview` for a while; the list resource never did, so an episode list had
nothing to describe an episode with.

**`runtime_minutes`** — measured from the file by the probe, not repeated from a
metadata source. `show_metadata` has no runtime column at all, and the probe's
number is the one that matches what actually plays.

Together these are what an episode row needs to look like one. A number and a
title is a file listing; the streaming apps show a still, a duration and a
sentence.

## Cost

`parent` and `probe` are eager-loaded wherever a list of items is serialised.
Measured on the Continue Watching endpoint with six episodes: **7 queries with
the eager load, 12 without** — exactly the six extra, one per card. The test
asserts a bound between the two, so it fails the moment the series name goes
back to being resolved per card. (A looser bound passed either way and proved
nothing; this one was set from the measurement.)

## Still to do

No client renders these yet. The iOS episode list is next — a season picker and
rows with a still, duration and synopsis, rather than the number-and-title row
it has now.
