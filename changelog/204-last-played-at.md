# 204 — The API says when you last played something

**Merged** 2026-09-25 · **Issues** S-391

`last_played_at` on every item, so a client can offer a "recently played"
sort. Null for something never played.

## What changed

Per **profile**, not per account: two people sharing a login have different
recent listening, and reporting one as the other would be worse than reporting
nothing.

Read from the already-loaded `plays` relation rather than queried — a listing
of 200 items would otherwise become 200 queries. `LibraryController::visible()`
now eager-loads `plays` for the same reason: without it the field would have
been silently null on every row of the main listing.

## Worth knowing

- Additive. A client that ignores the key is unaffected.
- When no profile resolves, the field is **null** rather than the newest play
  by anyone. An unfiltered fallback would report a housemate's listening as
  yours, which is the kind of wrong that looks like a feature.

## Still wrong

Nothing found here. The iOS side — Albums/Songs tabs and the sorts that use
this — ships separately.

## Tests

PHP · `LastPlayedAtTest` 5/5: it reports a play, reports nothing for an
unplayed track, takes the newest of several, ignores another profile's play,
and reports null rather than querying when the relation is not loaded.

Related suites 21/21, including the list page-cost guard.
