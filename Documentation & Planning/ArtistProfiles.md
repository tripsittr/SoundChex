# Artist credits and profiles

Real artists, with real credits — the record of who played on what, and a page
per artist worth visiting.

## What is wrong now

Artist is one free-text string carrying every credit on the track, so a
collaboration is a different artist from the person who made it.

| | |
| --- | --- |
| Distinct artist strings | **1,034** |
| Containing a comma | 233 |
| Containing a slash | 8 |
| Distinct once the primary is taken | **883** |

`AlbumBrowser::forArtist()` matches that string exactly, so an artist's page
shows only the tracks credited to them alone:

| `$uicideboy$` | |
| --- | --- |
| Stored as exactly that | 342 tracks |
| Crediting them at all | 456 tracks |
| **Missing from their page** | **114** |

## What already exists

More than it appears. `media_item_person` is already a credit table —
`person_id`, **`role`**, `character`, `sort_order` — carrying author, actor,
director, producer and writer for books and film. `people` already has
`musicbrainz_artist_id` and `headshot_url`.

Music has simply never used any of it. 33 rows, all book authors.

So this is not a new system. It is the existing one, extended to music, with
the free-text column kept as what the file says.

MusicBrainz gives all of it away, and needs no API key:

- **`artist-credit`** on a recording — every artist separately, each with a
  stable MBID and the join phrase between them. Already fetched by
  `MusicBrainz.php` and currently reduced to `['artist-credit'][0]['name']`.
- **Artist lookup** — type (person or group), country, life span, and a
  disambiguation line.
- **`url-rels`** — images via Wikimedia Commons, plus Wikidata, Wikipedia,
  AllMusic and Discogs links. Avicii returns 49 relations.

## What this is not

**Not a re-file.** `LibraryOrganizer` builds `Artist/Album/` from
`music_metadata.artist`, so changing that column moves files on disk. The
credits sit beside it. A browsing feature must not rearrange a library.

## Plan

### 1. Music credits in the table that already holds credits (~1.5 days)

Roles for music on `media_item_person`: `primary_artist`, `featured_artist`,
`remixer`, `composer`, `producer`. `sort_order` preserves billing order, which
is the whole point of a credit.

A `MusicCredits` service writes them from two sources, in order of trust:

1. **MusicBrainz `artist-credit`** — separate artists with MBIDs, no parsing.
2. **The credit string**, where MusicBrainz has no match. `ArtistCredits`
   splits on `,` and `/` and takes the first as primary.

Two cases the string rule must not break:

- **`Hank Williams, Jr.`** is one artist, and is in the library. Guard the
  generational suffixes before splitting.
- **`Earth, Wind & Fire`**, `Crosby, Stills & Nash` — the comma is part of the
  name. None are in the library today, which makes a naive rule safe now and
  silently wrong the day one arrives. An explicit exception list.

None of 40 sampled multi-artist files carry an `album_artist` tag, so the
string has to be parsed rather than read. Where a file does carry one it is
the better answer, and `FileTagger` should prefer it — the opposite of today.

### 2. A denormalised primary, for browsing (~half a day)

Grouping and pagination happen in SQL, and a join per artist row does not
paginate well. `music_metadata.primary_artist` mirrors the credit with role
`primary_artist`, maintained whenever credits are written.

The credits are the truth; this column is the index.

### 3. Backfill, dry run first (~1 day)

`music:derive-credits`, dry run by default, printing what it would write. It
touches roughly a quarter of the library, so `--apply` is deliberate and a
backup is taken first. Resumable, because MusicBrainz is rate limited to one
request a second and 1,438 tracks is half an hour of politeness.

### 4. Browse by primary, and "Appears on" (~1 day)

`artists()` and `forArtist()` group on `primary_artist`, falling back to
`artist` where it is null. A new `appearsOn()` returns tracks credited to this
artist whose primary is someone else — the 114 above.

The artist page grows an **Appears on** section under albums and singles,
rendered only when there is something in it.

Every query guarded by `ContentGate`, or a capped profile reaches an album
through a collaboration that the album grid correctly hides.

### 5. The profile itself (~2 days)

`people` gains the music rows it never had, keyed on `musicbrainz_artist_id`:

- **Image**, fetched from the Wikimedia Commons relation and stored locally
  rather than hotlinked.
- **Biography** from Wikipedia via the Wikidata relation.
- **Type, country and years active** from the artist lookup.
- **Discography** — albums by release year, singles, then appearances, which
  is the shape every music app uses.

The page falls back to exactly what it renders today when a lookup finds
nothing, which is most of what a self-hosted library holds.

### 6. Tests (~1.5 days)

- The split: `Alan Jackson, Jimmy Buffett` → `Alan Jackson`;
  `$uicideboy$/Maxo Cream` → `$uicideboy$`.
- **`Hank Williams, Jr.` survives**, and so does a name from the exception
  list.
- MusicBrainz credits **outrank** the parsed string when both exist.
- Billing order survives a round trip.
- The backfill's dry run writes nothing, and a second run changes nothing.
- A collaboration appears under **Appears on** and *not* under albums.
- A capped profile sees neither.
- An artist with no MusicBrainz match still renders.
- Re-running enrichment does not duplicate credits.

**Total: ~7.5 days.**

## Deliberately not doing

- **Splitting `music_metadata.artist`.** It is what the file says, and filing
  depends on it.
- **Related artists, top tracks, monthly listeners.** All want listening data
  or a recommendation model the library does not have.
- **Merging people across media.** An author and a musician with one name stay
  separate rows until there is a reason to join them.

## Not started
