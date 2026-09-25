# 199 — Smart shuffle

**Merged** 2026-09-24 · **Issues** S-289

Shuffle now has a third state. Pressing the button cycles **off → shuffle →
smart shuffle → off**, on the web player and on iOS.

## What changed

Ordinary shuffle is uniform: on a library of 8,313 tracks that means mostly
things nobody has chosen. Smart shuffle draws roughly two thirds from what the
profile actually plays and a third from everything else, interleaved so the
queue does not read as two playlists stuck together.

### What it ranks on, and what it deliberately ignores

Play count and recency. Something played ten times last week outranks
something played ten times a year ago, as a gentle decay rather than a cliff.

The richer signals every article recommends are **not usable on this data**:

| Signal | Reality here |
|---|---|
| `completed` | set on 91 of 2,452 plays |
| `listened_seconds` | null on 93% of rows — skip detection would read noise |
| `user_rating` | unset on every item in the library |

Ranking on those now would be ranking on an accident of when a flag happened
to be written. They can be added when they fill in.

### Weighted, not top-N

A favourite is *likelier*, never certain — taking the top N would play the
same queue every time, which is the opposite of shuffle. Measured against the
real library: a top-20 track appears **8.6× more often** than uniform would
give, while the queue still reaches the rest of the library.

## Worth knowing

- Built server-side for both clients. The browser or phone would need the
  whole library *and* the whole play history to weight anything.
- `GET /api/v1/library/shuffle?smart=1` for the app;
  `/app/shuffle?smart=1` for the web player. Both default to a plain uniform
  shuffle without the flag, so nothing changes for existing callers.
- A failure falls back to ordinary shuffle rather than staying in "smart"
  while behaving uniformly — a mode that quietly means nothing is worse than
  an honest one.
- The web player's stored `shuffle: true` preference is read as the new "on",
  so an existing preference survives.

## Still wrong

Recommendations proper — personalised rows, collaborative filtering — are not
here. That was deliberate: smart shuffle proves whether the signal is worth
anything before building the larger thing on top of it.

The draw is per profile, so a fresh profile gets a uniform shuffle until it
has some history. That is correct rather than a gap, but it does mean the
feature is invisible on a new install.

## Tests

PHP · `tests/Feature/SmartShuffleTest.php` 9/9: a played track outranks an
unplayed one, a recent play outweighs an old one of the same count, discovery
still reaches unplayed tracks, no queue repeats a track, no history falls back
to plain shuffle, another profile's history does not steer this one, unplayable
items are never queued, and both API modes respond.

Related suites 144/144.
