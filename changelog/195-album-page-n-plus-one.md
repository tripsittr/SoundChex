# 195 — The album page stops querying per track

**Merged** 2026-09-24 · **Issues** S-362

A 14-track album page ran **90 queries**. It now runs **7**, and the cost no
longer grows with the album.

## What changed

The test that caught this said the resume positions were being fetched one at
a time. They were not — those have been batched for a while. The 84 extra
queries were all the same one:

```sql
select "id" from "media_items" where "duplicate_of_id" = ? and ... is not null
```

Six per track, from `readableLinkedCandidates()`. A track whose file path is
stale consults its linked duplicate rows for a surviving readable copy, and
**both** the repoint attempt and the fallback call that helper — which then ran
`$this->duplicates()->pluck('id')` fresh every time.

Two fixes, because either alone leaves half the queries:

- **Memoised per instance.** The album page asks each track for a playback path
  several times while building its payload; the answer cannot change mid-request.
- **Read through the relation, not a fresh query.** `->duplicates()->pluck()`
  ignores an eager load and asks again. `->duplicates->pluck()` uses it.
  `AlbumBrowser` now eager-loads `duplicates` alongside `musicMetadata` and
  `plays`, at all three call sites that feed `playerPayload()`.

## Worth knowing

- No schema change, no data change. Every remaining query on that page is a
  single batched lookup.
- The memoised value is per instance, not cached across requests, so a repoint
  during the same request is still seen by the fallback that follows it.
- The stale-path lookup itself is unchanged — this only stops it being asked
  the same question repeatedly.

## Still wrong

The original test's message ("the resume positions are being fetched one at a
time") was misleading and cost some time. It now sits beside a second test
that asserts the *bound* rather than a number: a 40-track album must not cost
proportionally more than a 14-track one.

## Tests

PHP · `tests/Feature/ListPageCostTest.php` 7/7 — the long-failing
`test_an_album_page_does_not_query_per_track` passes, and a new
`test_the_album_page_cost_does_not_grow_with_the_album` covers the shape of the
bug rather than one album size. Verified by reverting the eager load: 20 vs 44
queries, and the new test fails as it should.

**Full suite 971/975, 4 skipped, no failures** — the first fully green run in a
while; this was the last outstanding one.
