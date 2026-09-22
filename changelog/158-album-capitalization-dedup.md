# 158 — Collapse albums duplicated only by capitalization

Different metadata sources title-case small words differently — "Cage The
Elephant" vs "Cage the Elephant", "Have A Nice Day" vs "Have a Nice Day" — so one
album ended up split into several near-identical entries. 24 album+artist groups
in the library were duplicated this way.

## Added

- **`AlbumTitleNormalizer`** — for each album (per artist, case-insensitively),
  finds the spelling that appears most often in the library and treats it as
  canonical. "Most common wins" rather than a mechanical title-caser, so the
  library's own prevailing spelling is kept and deliberate stylings (all-caps
  album names) survive.
- **`php artisan music:normalize-albums`** — lists what it would change; `--force`
  rewrites every off-casing to the canonical spelling. (Run once on this library:
  37 tracks across 24 albums collapsed; 0 capitalization duplicates remain.)
- **Enrichment adopts the prevailing spelling.** After a track is enriched, its
  album is normalized to the library's canonical casing, so a newly-imported
  track no longer re-creates a capitalization duplicate. A genuinely new album
  keeps its own casing.

## Testing

- `AlbumTitleNormalizerTest` (4 pass): most-common spelling wins; a consistent
  album is untouched; different artists with the same album name aren't merged; a
  new album keeps its casing.
- Enrichment suite unaffected.
