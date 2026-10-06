# 271 — Versions are kept, not merged

**Merged** 2026-10-06 · **Issues** #457, #475, #476, #487

The rule the user set: *"If Spotify has 15 versions of a song for an artist, we
should too."* So a second copy that differs in any meaningful way is a **version
to keep**, and the burden of proof is on calling something a duplicate.

## Why this existed to be fixed

One row was one file was one work, so the only verdicts available were
"duplicate" or "not". Measured on this library: **~29% of identifier-matched
"duplicates" were the same recording on a different release** — the album cut
and the greatest-hits copy — and the owner was being asked to adjudicate pairs
that both belonged.

## What changed

### `work_key`, `edition`, `is_primary_version`

Three columns, not a table. A version is not a new kind of thing — it is a
`media_items` row that happens to share a work with another, and clients,
plays, playlists and the API all key on `media_items`.

`work_key` is derived **only from identifiers a provider issued**
(`mb:recording:…`, `isrc:…`, `tmdb:movie:…`, `tmdb:tv:1396:s01e02`,
`isbn:…`). A key built from a title would group two different songs that share
a name, which is the false-duplicate problem this is meant to end. A file
nothing has identified has no key and takes no part in grouping.

### Six verdicts instead of two

| verdict | review? | both kept? |
|---|---|---|
| `same_file` | yes | — one file, two rows |
| `identical_copy` | yes | no |
| `duplicate` | **yes** | no |
| `quality_variant` | no | **yes** |
| `version` | no | **yes** |
| `unrelated` | no | **yes** |

Only `same work + same edition + same quality tier` is a duplicate. A version
is **information, not a question**, so it never reaches the review queue —
putting them there is what made the queue feel like busywork.

### Edition detection is load-bearing

If it is weak, two different recordings merge; if it invents editions, versions
that should group get split. Both are losses and the second is quieter.

- **A year stays with the marker.** `remaster_2009` ≠ `remaster_2012`: those are
  different masters and merging them deletes one.
- **An unknown marker is kept, not discarded.** `Rough Trade Version` becomes
  `rough_trade_version` — a marker this code has not met still distinguishes two
  files, and treating it as "no edition" would merge them.
- **`Album Version` means the plain release**, which is not the same as unknown.
- **An artist's name in the title is not an edition.** 126 of this library's 772
  suffixed titles are the artist written into the title (#452) — `R E M`,
  `Portugal The Man`, `Mt Joy`. Compared without spaces or punctuation, because
  the title's copy is rarely spelled the way the artist field spells it.

### A stale hash is no longer treated as proof (#487)

Found because it produced a wrong answer, not by reading code. The grouper
called these identical copies of each other:

```
id 2353  (Ghost) Riders in the Sky  [Silver]                  5,425,567 bytes
id 3961  (Ghost) Riders In the Sky  [The Essential]           5,381,369 bytes
```

Same hash, 44 KB apart — impossible for identical files. **2,298 pairs in this
library share a hash across different sizes**, which is the S-346 stale
fingerprint condition: rule 2 was added and the organizer fixed, but the
existing bad data was never cleaned.

A matching hash now requires matching sizes before it is believed. A size
mismatch does not make two files unrelated — it makes the hash unusable, so the
comparison falls through to work and edition like any other pair. **The data is
still wrong and is logged as #487.**

### Primary version decides playback, not visibility

The plain release wins, then quality, then the earliest row so the answer never
flickers. It only resolves an ambiguous "play this song" — a playlist entry, a
shuffle. **Every version stays browsable in its own release**, which is what
the user asked for and the opposite of a hidden picker.

## Worth knowing

- **Nothing is backfilled yet.** Existing rows have no `work_key` until
  something classifies them; the dedupe stage and a backfill command come next.
- Verified against the real `(Ghost) Riders in the Sky` rows the user corrected
  me on: the 154s *Another Smash!!!* copy has a **different MBID** and is
  correctly `unrelated`; the live cut is correctly a separate edition; and the
  two that share an MBID are correctly a `duplicate` for a person to judge.
