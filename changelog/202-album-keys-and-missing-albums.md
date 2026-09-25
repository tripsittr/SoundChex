# 202 — Albums stop duplicating, and a missing album gets noticed

**Merged** 2026-09-25 · **Issues** S-383, S-384

Two problems from the same screenshot of the `$uicideboy$` artist page: the
same album listed twice, and tracks gathered under "Unknown album".

## The same album, twice (S-383)

`canonicalKey()` strips punctuation to compare spellings — but a band that
writes its name **`$UICIDEBOY$`** means an S, and stripping deleted the
character outright:

```
DIRTIERNASTIER$UICIDE  ->  dirtiernastieruicide
DirtierNastierSuicide  ->  dirtiernastiersuicide
```

Two keys that could never meet, so the same record showed as two albums.
Currency symbols are now folded to their letters before stripping.

**Digits are deliberately not folded.** That was tried and reverted: it turns
`Blink-182` into `blinki82`, `Sum 41` into `sum ai` and `Album 3` into
`album e`, merging albums that are genuinely different. A wrong merge hides
music; a missed one only duplicates a tile, so the cautious failure is the
right one.

### And one found on the way

A title that is *only* punctuation stripped to an empty key, so Ed Sheeran's
`=` and `+` and XXXTENTACION's `?` all shared one. Such a title now keeps
itself as its key.

## A missing album was never flagged (S-384)

83 tracks had no album and every one was marked `complete`, so nothing
surfaced them — they sat unnoticed until a client grouped them under "Unknown
album", which is where the problem got *noticed* rather than where it
happened.

Enrichment cannot tell "a single with no album" from "an album tag we failed
to read". A person can, in a second, so it now asks: a music track with no
album goes to the review queue. Dismissing one stamps `reviewed_at`, which is
checked so a later re-enrichment does not drag it back — the trap S-302 fixed
for match review.

## Worth knowing

- **Run on this machine's library**, after a backup
  (`soundchex-2026-09-25_050828.sqlite.gz`): 90 tracks rekeyed across two
  passes, 83 flagged for review. 24 album keys now group more than one
  spelling.
- **No client change needed.** `MediaItemResource` already sends `album_key`,
  and iOS already groups on it in preference to the album string — so the
  merge arrives over the API on the next sync.

## Still wrong

Whether `DIRTYNASTY$UICIDE`, `DIRTIERNASTIER$UICIDE` and
`DIRTIESTNASTIEST$UICIDE` are three records or one is a question about the
discography, not the keys. They are kept apart, which is the safe answer.

The 83 flagged tracks are mostly singles and one-off features, so many will be
dismissed rather than filled in. That is the queue working, not noise — but it
is 83 items to get through.

## Tests

PHP · `AlbumTitleNormalizerTest` 11/11, four new: the `$`/S merge, three
stylised albums staying apart, numbers left alone, and punctuation-only titles
keeping their identity. `EnrichmentWritesCreditsTest` 9/9, three new: a track
with no album is flagged, one with an album completes, and a human's judgement
is not overruled.

Related suites 119/119.
