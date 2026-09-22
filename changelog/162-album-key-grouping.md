# 162 — Albums group by a canonical key, closing the duplicate gap

The edition/punctuation dedup (#161) fixed existing duplicates and stopped a new
variant re-splitting an album you already had. But two variants imported in the
*same fresh batch* — before either was saved — could still each keep their own
name and show as two albums. This closes that gap at the source: albums are
grouped by a stored canonical key, so variant spellings can never display twice
no matter how or when they were imported.

## Added

- **`album_key` on `music_metadata`** (indexed) — the canonical grouping key from
  `AlbumTitleNormalizer` (case-, quote-, bracket- and edition-insensitive, but
  keeping numbered sequels apart). Set automatically whenever a row is saved, so
  it always tracks the album. Backfilled by `php artisan music:backfill-album-keys`
  (idempotent).
- The reader/browse **API sends `album_key`** on each track, so clients group on
  it too.

## Changed

- **`AlbumBrowser` groups on `album_key`**, not the raw album string: the album
  grid, an artist's albums, and an album's tracklist all collapse edition and
  punctuation variants into one album. The plainest spelling is shown; numbered
  sequels ("(Part IV)", "(II)") stay separate.

## Testing

- `AlbumAndPlaylistTest` (34 pass) incl. new cases: edition variants group into
  one album; an album's tracks gather every variant; numbered sequels stay
  separate.
- Album, browse-pagination and normalizer suites: 43 pass together.
- Backfilled the library (8,231 tracks); the batch-import gap verified closed —
  two unmerged variant strings still show as one album.
