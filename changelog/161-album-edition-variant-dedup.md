# 161 — Collapse album duplicates from editions and punctuation, keep sequels

The capitalization fix (#158) was too narrow. The same album was still split into
duplicates by **edition/version tails** and **punctuation** — the way streaming
metadata actually differs:

- "Urban Hymns" · "Urban Hymns (Remastered 2016)" · "Urban Hymns (Deluxe /
  Remastered 2016)"
- "American Idiot" · "American Idiot (Deluxe)"
- "(What's the Story) Morning Glory?" · "[What's The Story] Morning Glory" ·
  "(What's The Story) Morning Glory? (Deluxe Remastered Edition)"
- smart vs straight quotes ("Don't Forget Me" vs "Don't Forget Me"), region tags
  ("(U.S. Version)")

## Changed

- **A canonical album key** groups the same album written different ways —
  folding away case, smart/straight quotes, bracket style, and edition/version
  qualifiers — while **keeping genuinely different releases apart**: a numbered
  sequel or volume ("(Part IV)", "(II)", "XVII") is never merged, because only
  qualifiers carrying an edition keyword are stripped.
- **The plain album name wins the display.** When a group has both a plain title
  and "(Deluxe)"/"(Remastered)" spellings, the plain one is canonical — the
  edition tail is metadata noise, not the album's name.
- Grouping, the cleanup command, and enrichment-time normalization all use this
  key, so a newly-imported deluxe pressing collapses onto the plain album already
  in the library instead of forming a new one.

## Testing

- `AlbumTitleNormalizerTest` (7 pass): edition + bracket + quote variants collapse
  to the plain title; numbered sequels ("(II)", "(Part IV/V)") are **not** merged;
  a deluxe pressing adopts the plain album at enrichment; casing and per-artist
  scoping still hold.
- Applied to the library: **171 tracks across 93 albums** collapsed; 0 duplicate
  album groups remain, and the $uicideboy$ sagas/volumes stayed distinct.
