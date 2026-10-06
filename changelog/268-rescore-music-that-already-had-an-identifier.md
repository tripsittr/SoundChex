# 268 — Re-score music that already had an identifier

**Merged** 2026-10-06 · **Issues** #485, part of #466

The plan said the library read `match_confidence = none` because the
MusicBrainz recording id was probably never read. Measuring said otherwise, and
the real problem is different and cheaper to fix.

## What the library actually says

On the Mac library, 8,314 music rows:

| confidence | with an MBID | without | total |
|---|---|---|---|
| `exact` | 2,621 | 0 | 2,621 |
| `fuzzy` | **3,429** | 1,622 | 5,051 |
| `none` | 0 | 642 | 642 |

So **6,050 rows do carry a recording id** — it is being read. Only 642 read
`none`, not 8,440, and every one of those has no id, which is consistent rather
than broken.

The real finding is the 3,429 rows that **have** an id and score `Fuzzy`
anyway, all matched by MusicBrainz. They have no `enrichment_report` (1 of
3,429, against 2,519 of the `exact` rows) and no `reviewed_at` (0, against
2,321), so they never went through the current pipeline — MusicBrainz wrote the
id and the confidence came from an older path.

Running one through the live API settled it:

```
before: conf=fuzzy  mbid=794e82be
after : conf=exact  matched_by=MusicBrainz
```

The code is already correct. The data is stale.

## What changed

### `music:rescore`

Re-resolves the id on every row that has one and does not score exactly. On
this library that is 3,422 rows, and it should move roughly 32% `Exact` to
roughly 73%.

**It calls the API rather than trusting the column.** Promoting a row because
an id is *present* is the exact mistake #459 fixed — a dead or mistyped id must
not score exact — so each id is actually resolved and a row whose recording
MusicBrainz has merged away correctly stays a guess.

Rate-limited to MusicBrainz's stated 1/s, so a full run is about an hour
unattended; the command says so up front rather than looking hung. `--dry-run`
lists what would change, `--limit` takes a slice, and one failing row is
counted and carried rather than ending a run of thousands.

### Two real bugs in `music:reenrich`

Both named in the audit and both verified:

- `$item->match_confidence !== 'none'` compared an **enum to a string**, which
  is always true — so every row counted as matched and the summary was
  meaningless however the run went.
- `'('.$item->match_confidence.')'` throws *"could not be converted to
  string"*, so `--sample` died the moment a title actually changed — exactly
  when it had something to show.

### Scope is any identifier MusicBrainz resolves by (a5's review)

Either a recording id **or** an ISRC, because both are routes
`resolveRecording()` resolves by and both therefore earn `Exact` when they
land.

a5 asked whether ISRC-only rows were meant to be in scope. On this library the
answer is moot — all 3,598 rows with an ISRC also carry an MBID, and there are
**zero** ISRC-only and zero AcoustID-only rows — but that is a coincidence of
one library rather than a guarantee, and a scope that is only accidentally
complete is the kind that silently misses rows on somebody else's.

AcoustID stays out: resolving a fingerprint means computing it from the file
with `fpcalc`, which is #466's work rather than a database re-score.

## Worth knowing

- **The re-score has not been run in full.** 7 rows were promoted while
  verifying it (6 of 6 in one batch, plus one by hand), leaving 3,422. A full
  run takes about an hour and is the user's to start.
- The MBID-spelling work from the plan (reading `MUSICBRAINZ_TRACKID`, `TXXX`,
  UFID) is still worth doing for Picard-tagged files whose id is not read at
  all — it is just not the cause of the headline number, and not urgent.
- The 642 genuinely unmatched rows are the ones that need the scorer, AcoustID
  fingerprinting and the parser fixes. A far smaller problem than 8,440.
- **a5 should check the same numbers on the Windows library** before assuming
  this picture holds there; the plan's 8,440 figure came from somewhere.
