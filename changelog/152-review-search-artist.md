# 152 — Review search matches the artist, not just the title

The Needs Review searchbar only matched the item **title**. On the Metadata
tab a track's artist and album show right under the title (the subtitle), so
typing an artist's name — the obvious thing to do when hunting their songs —
found nothing unless it also happened to be in the title.

## Changed

- **The Item column's search now also matches the track's artist and album.**
  A search term is matched against `title` OR the `musicMetadata` artist/album,
  so typing an artist surfaces their songs in the review queue. The same table
  renders in the browser and the desktop client, so both gain it.

No change to what's shown or to the other tabs' columns.
