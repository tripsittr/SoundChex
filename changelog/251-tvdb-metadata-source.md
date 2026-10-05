# TVDB

A second television source, for episode artwork and the id nothing has written.

## Why

TMDB runs first at priority 1 and answers most of what a series needs, so this
is not a second opinion — every write goes through a blank check. What TMDB is
weakest at is per-episode imagery, and an episode with no still shows a
placeholder in every list it appears in. On a library of 293 episodes that is
most of the television section.

`show_metadata.tvdb_id` has existed since the table was created and nothing has
ever written to it. Storing it is the other half: it is the id that alternate
episode orderings are keyed by, which is the next thing anyone wants for anime
and for series whose DVD order differs from broadcast.

## What it writes

Series rows get the TVDB id, and network, status, first and last air year and
content rating **only where TMDB left them empty**. Episode rows get the still,
and a title and air date if nothing has them.

Two judgement calls worth knowing about:

- **Status is translated, not stored raw.** TVDB says "Continuing" where TMDB
  says "Returning Series". TMDB wrote this column first, so its vocabulary is
  the one kept; a second source using its own words would make the field mean
  two things.
- **The content rating prefers the US one.** TVDB returns every country's
  certification and the column holds one value, so it has to pick. The existing
  data came from TMDB and is US-biased, so matching that keeps the column
  comparable.

## The episode is verified, not trusted

Season and episode go to TVDB as query parameters, and the response is checked
against them before anything is written. Writing one episode's name and still
onto a different file is the one failure this source must not introduce, and a
filter is a request, not a guarantee.

Checked by removing that verification: the wrong episode's still lands on the
file, and the test says so.

## Authentication

TVDB v4 takes a key over POST and returns a bearer token good for about a month.
The token is cached for a week — long enough to cost one login per sweep, short
enough that a revoked key stops working in days. A 401 drops the cached token so
the next call logs in again rather than repeating a request that cannot succeed.

A failed login is logged at error level. A wrong key is otherwise a source that
silently does nothing, which looks exactly like a source with no data.

## Still broken

- **Unverified against the live API.** There is no TVDB key on this machine, so
  the shapes are implemented from the v4 documentation and tested against
  fakes. If a field name is wrong, the source will quietly write nothing —
  every path is defensive — but it will also not work. **This is the main thing
  to check with a real key.**
- **Alternate episode ordering is not used.** Storing the id is the
  prerequisite; there is nowhere to put a second ordering yet.
