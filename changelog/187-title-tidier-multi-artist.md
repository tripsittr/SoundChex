# 187 — Title Tidier handles collaborations

**Merged** 2026-09-24 · **Issues** S-365

Tracks credited to more than one artist kept the credit in their title —
`$uicideboy$, Maxo Cream - Pictures` stayed as it was, because the tidier only
ever tried each artist name on its own and neither name was the whole prefix.

## What changed

### Credits are split into names, then offered back joined

A tagger records a collaboration however it likes. The artist field on the
affected `$uicideboy$` tracks reads `$uicideboy$/Maxo Cream`, while the title
says `$uicideboy$, Maxo Cream - …`: slash in one, comma in the other, so
neither string ever matched. The tidier now splits every credit on the usual
separators (`/`, `,`, `&`, `and`, `x`, `feat.`, `ft.`) and tries each
individual name, then the names re-joined by each of those separators. One of
those spellings is the one the tagger wrote into the title.

Longest candidate first, so the fullest credit strips rather than a single
name leaving the rest of the credit behind.

### Underscores and spaces are the same separator

`Plague_tsc` in the artist field against `Plague tsc - Creature From The Crypt`
in the title is one artist, not two. Either character now matches either.

## Worth knowing

- No migration. This changes what *future* enrichment writes; it does not
  rewrite titles already on disk. Existing records are cleaned up separately.
- Title tidying only runs when the bundled Title Tidier plugin is enabled,
  which it is not by default — see S-361. On this install nothing below will
  take effect until that lands.

## Still wrong

Splitting on ` and ` and ` x ` will mis-split an artist whose name genuinely
contains those words as separators (`Above and Beyond` survives, because the
unsplit credit is tried first and matches, but a title carrying only the split
half would not). Not seen in the real library; worth revisiting if it bites.

## Tests

PHP · `tests/Feature/TitleTidierTest.php` 19/19, up from 12 — new cases cover
comma/ampersand/`feat.` joins, slash-credit-to-comma-title, underscore and
space equivalence, and two guards against over-stripping.

`tests/Feature/EnrichmentWritesCreditsTest.php` is 5/6: the end-to-end
`test_a_title_holding_its_artist_is_tidied_before_filing` still fails, because
the filter that does the tidying lives in a plugin that is disabled. That is
S-361, not this change — verified by calling the tidier directly, which
returns the right answer for all four affected library records.
