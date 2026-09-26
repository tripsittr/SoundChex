# Cast, crew and the metadata that was already there

S-412 (server half).

A movie or show detail page on iOS showed almost nothing. Two reasons, both on
this side.

## The API was sending a fraction of what it had

`movie_metadata` has sixteen columns; the resource sent four. So the apps could
show a year, a runtime, a rating and a director — and nothing that helps anyone
decide what to watch. Now also sent: tagline, studio, language, country, IMDb
rating, Rotten Tomatoes score and the IMDb id.

Shows were thinner still: four fields out of twenty. Now also sent: creator,
network, first and last air years, season and episode counts, status and the
episode air date.

No new data, no pipeline change — the database already held all of it.

## Credits were not exposed at all

`media_item_person` holds a real cast and crew model — person, role, character,
billing order — and **8,323 items in this library carry credits**. None of it
reached any client.

`GET /api/v1/items/{id}/details` returns cast, crew and tags.

**Deliberately not in the library sync.** Every device mirrors the whole
catalogue, and putting credits in it would mean downloading every actor of
every film so a detail page could show a handful. The sync stays lean; this
fills in when a page is actually opened.

Cast and crew arrive split rather than as one list with a role on each: a
detail page shows them under separate headings, and otherwise every client
writes the same partitioning code. Billing order is preserved, because the lead
is not whoever was inserted first.

## Still missing: the description

There is no overview, plot or synopsis column anywhere — not on `media_items`,
not on either metadata table. `tagline` is the closest thing, and it is a
marketing line rather than a description. TMDB returns an overview and the
enrichment pipeline discards it.

So "show the description" still needs a migration and a pipeline change. That
is the remaining piece of S-412 and the larger one; this changes nothing about
what is stored.

## Testing

5 tests, 1,112 passing. They cover the cast/crew split, billing order, an item
with no credits returning empty lists rather than erroring, auth, and the wider
movie payload actually reaching the library response.

Checked against the real catalogue: one film returns 12 cast and 13 crew, and
its metadata went from 4 keys to 9.
