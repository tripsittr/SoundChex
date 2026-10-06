# Genres, and the facts a detail page shows

Tracker #505. Groundwork for the detail pages asked for on iOS (#502), built
server-side first so every platform reads one shape.

## The API was sending nulls where genres should be

`/api/v1/items/{id}/details` did `$item->tags()->pluck('name')`. **`media_tags`
has no `name` column** — it is `type` and `value` — so every row plucked null and
the endpoint returned `[null, null, null]`.

Measured: **11,704 genre tags across 309 distinct genres** are stored, and the
API was sending none of them. The film this was found on has *Horror*, *Mystery*
and *Science Fiction* recorded, and was sending three nulls.

Genres are now returned, and separately from `tags` as well as within it, because
a detail page shows them on their own line and every client would otherwise
filter the same list the same way.

## A `detail` block, one shape for every type

A detail screen wants *what is this, who made it, how long is it, how was it
rated*. That was spread across four metadata relations with a different column
name per type, so each client would have written its own branching.

One flat block instead — year, runtime, director, studio, creator, network,
season and episode numbers, content rating, tagline, language, country, IMDb and
Rotten Tomatoes scores, IMDb and TMDB ids, artist, album, author, file size, date
added. **Keys that do not apply are absent rather than null**, so a client renders
what is present instead of testing each key.

`imdb_rating` and `rt_score` are carried even though nothing fills them yet — the
columns have existed all along and OMDb is the source (#504). They will appear
without any client changing.

## What was already there, contrary to the plan

Worth correcting, because it narrows the remaining work considerably. The tracker
items said there was no credits table and no overview column. Both were wrong —
inferred from `movie_metadata`'s column names rather than read from the code:

- a `people` table with **2,946 rows, every one carrying a `headshot_url`**,
  joined through `media_item_person` with role, character and billing order, and
  filled by TMDB's `writeCredits()`
- overviews, which TMDB writes into `media_items.notes`

Both movies on this server already carry 25 credits and an overview, and the
endpoint was already returning cast and crew split apart. So what is genuinely
missing for #502 is **awards** (no column anywhere) and the **ratings** above —
not a credits system. #502 and #503 are corrected on the tracker.

## Also found, logged not fixed

**93% of stored track numbers are wrong** (#507). Of 8,233 rows with one, 7,654
exceed 100: they are filename index prefixes read as track numbers — `906
Stressed Out.mp3` → track 906. One stored `923` against a file named `927`, so
they are not even consistently the filename. `FileTagger::stripIndexPrefix()`
already guards artist names this way; nothing guards the track number.

It matters slightly beyond cosmetics: PR #285 answers "no album — is this a
single?" by reading the track number. The inference fails safe here (it says the
file came from an album, which for these is still true) but it rests on a field
that is mostly wrong.

## Verified

- **294 tests pass** across the Api, Media, Library and Detail groups; 5 new.
- The genres fix was checked by restoring `pluck('name')` — both new genre tests
  fail against it.
- Exercised on the **live library**: a real film returns its three genres,
  director, year, runtime, rating, tagline, file size and TMDB/IMDb ids; a music
  item returns artist and album; a book returns its author.
