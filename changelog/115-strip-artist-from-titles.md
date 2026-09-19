# 115 — Strip an artist carried in a song title

*2026-09-19.* · **Issue** S-272

## Done

Thousands of tracks imported from "Artist - Title.mp3" filenames kept the artist
inside the *title* — "$uicideboy$ - Converting" instead of "Converting",
"Paranoid Android - Radiohead" instead of "Paranoid Android". Enrichment already
trimmed a trailing " - Artist" **suffix**; the **prefix** form ("Artist - Title")
went untouched.

- New `TitleTidier` service strips an artist a title carries on **either** side of
  a dash-style separator (`-`, `–`, `—`), trying the full credit before the
  primary artist so "$uicideboy$, Pouya - Song" loses the whole prefix. It is
  deliberately conservative: the artist must be a whole segment at an edge and the
  remainder must be non-empty, so a title that legitimately *is* the artist ("Neon
  Trees" by Neon Trees) or merely contains it ("In A Big Country" by Big Country)
  is never touched.
- `EnrichMediaItemJob` now tidies titles through this service (prefix **and**
  suffix), so new imports and re-enriches clean themselves.
- `music:tidy-titles` (with `--dry-run`) cleans the existing library in one pass
  without a full re-enrich.

## Worth knowing

- On the dev library the dry run cleans **2,257 titles**; spot-checks found no
  false positives (short results like "We" by Bon Iver, "I" by Lil Skies are
  legitimately short songs, correctly kept).
- Run `php artisan music:tidy-titles --dry-run` first to see the changes, then
  without `--dry-run` to apply them. Titles are read from `musicMetadata` artist
  and `primary_artist`, so it pairs with the primary-artist work (S-269).
- The in-progress full re-enrich (S-273) already tidies most of these as it runs;
  this command mops up the rest and covers tracks that never matched a provider.

## Tests

PHP: **675 passing** (+11). `TitleTidierTest` (data-driven) covers the prefix and
suffix forms, en/em dashes, the full-credit-first ordering, and — the ones that
matter — the guards: a title that is the artist, one that contains it, no
separator, the artist not at an edge, and an empty remainder are all left alone.
Pint clean.
