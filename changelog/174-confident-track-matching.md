# 174 — Playlist imports match what the library actually holds

**Merged** 2026-09-22 · **Issues** S-324

A playlist import sent far more tracks to manual review than it needed to. On a
real 530-track Spotify import, 119 tracks came back unmatched while only 36
were genuinely absent — the other 83 were songs the library already held.

## What changed

The matcher itself is in `soundchex-playlist-porter` v1.1.0. This carries the
tests that pin its behaviour, one per real failure.

### Why it was losing songs

Two things, both measured against that import rather than guessed at:

- **Collaborations could never match** (27 tracks). The artist comparison
  coalesced to `primary_artist`, so a credit stored identically on both sides —
  "Morgan Wallen, Florida Georgia Line" in the playlist *and* in the library —
  was compared against "Morgan Wallen" alone. Every one of the 27 matched on
  the raw artist column; none were genuinely different artists.
- **The fuzzy tier was not fuzzy** (56 tracks). It required the album to be
  equal and the length within three seconds, so the same song on a
  greatest-hits copy, or a remaster running a few seconds long, was discarded.

Matching is graded now: identifiers stay decisive, and otherwise the album and
length corroborate a match rather than veto one. A match is trusted when
nothing contradicted it, flagged when something did.

## Worth knowing

- **Migration** (in the plugin): adds `uncertain` to `playlist_imports`, the
  matches that were attached but disagreed on release or length. Run on the dev
  machine after a backup; it is additive and nullable.
- Matched 411 → 494 on that playlist, unmatched 119 → 36 — exactly the set the
  library does not hold, with no wrong artist among the 234 tag-based matches.
- Absence of an album or a length is deliberately *not* treated as doubt. An
  early version flagged all 234 tag-based matches, which buried the 60 that
  deserved attention.

## Still wrong

- The review list's fallback is still a raw "library track id" field. The
  ranked candidates make it unnecessary in the common case, but a track with no
  candidate at all still has no search.
- Plugin routes remain unavailable in the app's test environment (see 173), so
  these tests drive the matcher directly rather than the import endpoints.
