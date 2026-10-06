# Nothing was fetching show artwork

Every episode row was a grey TV glyph. The renderer was right and the
series-poster fallback was right — **nothing had ever fetched any artwork to
show**.

`Tvdb` writes episode stills and was the only source that did. It needs its own
API key. A server holding a TMDB key and no TVDB key therefore had nothing
fetching stills at all — while `Tmdb` was requesting the very episode record
that carries `still_path` and throwing the image away. The series path wrote no
`poster_path` either, so the fallback had nothing to fall back *to*.

Both are now stored, from responses the source was already fetching. No extra
API calls.

## Backfilling

Fixing the source only helps items enriched afterwards, and enrichment does not
re-run on its own — an already-enriched episode keeps its empty cover for ever.

`library:show-artwork` goes back for them. Series first, deliberately: an
episode with no still of its own falls back to the series poster, so fetching
the posters first leaves every row showing *something* even where TMDB has no
still. `--dry-run` reports without writing, and the command refuses outright
with no TMDB key rather than reporting "filled 0 of 400" with no reason.

## Notes

Images are stored as full URLs at `w780`. TMDB returns a bare path like
`/abc123.jpg`, which renders as a broken image if stored as-is — the same trap
the TVDB source already documents for its own relative paths.

Nothing overwrites: a cover that arrived beside the file, or one a person chose
by hand, still wins. That is the rule `LocalArtwork` and every other source
follow.

## Testing

9 new tests, **206 pass** across the show, artwork, enrichment and metadata
suites. Each confirmed to fail against the thing it guards: `still_path`
discarded, `poster_path` discarded, the overwrite guard removed, the dry run
writing, and the missing-key check removed.
