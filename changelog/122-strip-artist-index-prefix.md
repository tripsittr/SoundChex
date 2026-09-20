# 122 — Strip a playlist-index prefix out of the artist tag (S-275)

A handful of tracks were catalogued with a playlist position wedged into their
artist: `artist = "7373. Queer"` for a track whose artist is really Garbage,
and filed under a `7373. Queer/` folder to match. A bad playlist exporter had
written the export index into the ARTIST tag; it was read straight through and
stuck.

## Fix

- **`FileTagger` cleans the artist at read time.** A leading `NNNN. ` index
  (`^\d{2,}\.\s+`) is stripped before the artist is stored, so new imports are
  never filed under one.
- **`music:strip-artist-index`** cleans the rows already stored, using the exact
  same rule (a shared `FileTagger::stripIndexPrefix()`, not a second copy), and
  recomputes `primary_artist` for each row it changes so browsing regroups on the
  corrected name. `--dry-run` previews; idempotent otherwise. Cleaned the 5 rows
  on the dev library; none remain.

## Why the guard is narrow

Legitimate number-bands must survive: **38 Special, 21 Savage, 3 Doors Down,
60 Ft Dolls, 49 Winchester**. Those are a number then a *space*; the bug is a
number then a *dot* (`\d{2,}\.`). Requiring the dot — and only accepting the
strip when a real name is left, so a value that was *only* an index isn't blanked
— keeps every band intact. A single-digit `1. U2` is also left alone as too weak
to be a reliable index.

## Tests

- `StripArtistIndexPrefixTest` — strips real indexes, leaves every number-band
  and ordinary name untouched, handles index-only and null (13 cases).
- Full suite: 724 passed.
