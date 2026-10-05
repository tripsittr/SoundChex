# Last.fm

A seventh music source, for the identifiers more than the tags.

## Why it is worth having

The tags are the advertised reason and the smaller half of the value. On the
real library **10 tracks of 8,313 carry an ISRC, 28 a MusicBrainz recording id,
and none an AcoustID fingerprint**. Every duplicate decision and every exact
lookup therefore falls back to matching tag text — which the project's own notes
already flagged as the weak point of the whole metadata pipeline.

Last.fm answers a plain artist-and-title query with MusicBrainz ids for both the
recording and its release. That is the one cheap way to raise identifier
coverage without a fingerprinting key, and it makes the duplicate detector's
strongest signals usable on a library where they are currently empty.

Ids are written only into empty fields, so file tags and MusicBrainz itself
always win. An id is checked against the UUID shape before it is stored: that
column is used afterwards as a lookup key *and* as a duplicate signal, so a
malformed value would quietly pair two unrelated tracks.

**One ordering caveat.** This runs at priority 7, after MusicBrainz at 3, so an
id it supplies is not used by MusicBrainz until the item is enriched again.
Re-enrichment is a button and it is worth pressing once after switching this on.
Moving the source earlier would fix that and is worth considering separately —
it would change a documented pipeline order, which is not a decision to take
while adding a source.

## The tags are filtered, and that is most of the work

Last.fm's tags are a folksonomy, not a genre list. Alongside "shoegaze" and
"post-punk" come "seen live", "favourites", "albums i own", "00s", "british"
and "female vocalists". Written into `media_tags` as genres they reach the genre
filter and the genre chart — the library's one curated facet — and a genre list
containing "seen live" is a genre list nobody trusts again.

So each tag must survive `metadata_rules.tag_blocklist` before it is kept, at
most three per track. Decades and years are rejected in code rather than listed,
because they are a shape ("80s", "1990s", "2010") rather than a set.

Tags are **rejected, not canonicalised**. "seen live" is not a misspelled genre,
and mapping it to one would invent a fact. Aliases that genuinely are genres
still canonicalise through the existing `genre_aliases` map, the same as every
other source.

Checked by emptying the blocklist: the genre facet fills with "British",
"Favourites" and "Seen Live", which is exactly the failure being prevented.

## Also

Lookups go through `LookupCache`, like MusicBrainz and iTunes — 61% of queued
music lookups on this library are repeats. The api key is deliberately not part
of the cache key, so it never lands in a cache entry and two installs share
nothing; a test proves the same track is asked once even after the key changes.

An unknown track is an answer and is remembered (Last.fm returns 404 with an
error body). A failed request is not, and is retried.

## Still to do

- **The biography and similar artists are not imported.** `people.biography`
  exists and `artist.getInfo` would fill it, but matching a Last.fm artist to a
  `people` row needs care that does not belong in this change.
- **Listener and playcount figures are dropped.** There is nowhere to put them,
  and inventing a column for a number nothing reads would be worse.
