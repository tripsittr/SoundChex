# The ratings and awards nothing was filling

Tracker #504, and the data half of the detail pages asked for on iOS (#502).

`movie_metadata.imdb_rating` and `rt_score` have existed since the table was
created and **nothing ever wrote them**. Null on every row, because the source
that carries them was never built — and `omdb_api_key` was one of the seven keys
the integrations audit found that **no code reads**. Someone pasting a key got a
green card and no change in behaviour.

Awards had nowhere to go at all: no column on `media_items`, `movie_metadata` or
`show_metadata`.

## What OMDb is for, and what it is not

Deliberately narrow. TMDB already supplies title, overview, genres, cast, crew,
artwork and runtime, and does them better. OMDb is here for the four things TMDB
does not have: **the IMDb rating, the Rotten Tomatoes score, Metacritic, and the
awards sentence**.

Keyed by IMDb id — which TMDB writes first, hence priority 2 — never by title.
OMDb *can* search by title, but TMDB has already identified the item far more
carefully, and a title search here would risk hanging one film's ratings on
another.

The same class serves shows. OMDb answers for a series by IMDb id exactly as it
does for a film, and a show with no scores beside a film that has them reads as
a broken page rather than a gap in the data.

## Three shapes, because OMDb uses three

Rotten Tomatoes appears **only** inside the `Ratings` array and never as a
top-level field. Metacritic appears bare in one field and as `82/100` in the
array. The IMDb rating is a top-level decimal. All of this was confirmed against
the live API rather than assumed.

Awards are stored as the sentence OMDb writes — *"Nominated for 7 Oscars. 21 wins
& 43 nominations total"* — not parsed into counts. That sentence is what a detail
page shows, and parsing it would invent structure the source does not have.

## The trap: a failure arrives as HTTP 200

OMDb answers **200 with `{"Response":"False","Error":"…"}`** for a bad id *and*
for a bad key. The status alone means nothing; the body decides. Verified against
the live API.

The test for this was **vacuous on the first attempt** — a bare error body has no
rating fields, so nothing is written whether or not the flag is checked, and it
passed against an implementation that ignored `Response` entirely. It now sends a
failure flag *alongside* real-looking values, and fails if the flag is ignored.

## Verified

- **Against the live OMDb API**, on a real film in this library: IMDb **6.8**,
  Rotten Tomatoes **87**, Metacritic **76**, and its awards line. Those four
  columns were null on every row before this.
- The whole way through to the API: `/api/v1/items/{id}/details` now returns all
  four in its `detail` block.
- **123 tests pass** across Omdb, MediaDetails, Integrations and Metadata; 12 new.
- Existing tests updated rather than deleted: two asserted that `omdb_api_key` was
  unimplemented, which was true until now.

## Still not done

Six dead keys remain — Trakt, Discogs, Genius, Musixmatch, Google Books,
Fanart.tv — and the integrations page still says so honestly.

**A key is needed.** OMDb is free but rate-limited to 1,000 lookups a day on the
free tier, and nothing runs until one is entered under Integrations. Existing
films will not backfill on their own; they fill as enrichment re-runs.
