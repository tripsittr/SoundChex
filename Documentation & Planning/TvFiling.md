# TV Filing

Episodes need somewhere to go.

## The problem

`LibraryOrganizer` files a show as:

```
TV/Show/Show.mkv
```

There is no season or episode level, so every episode of a series resolves to
the same path. The second one becomes `Show (2).mkv`, the third `Show (3).mkv`,
and the library is unusable — the numbers reflect import order, not episode
order.

`config/library.php` already documents the intended shape:

```
TV/Show/Season 01/Show - S01E02.ext
```

The code never implemented it, and `show_metadata` has no fields to implement
it with: `season_count` and `episode_count` are series totals, not the
identity of an episode.

This is why nothing was filed as TV — the library has no shows yet, so the gap
never surfaced.

## Scope

**In:**

- Per-episode fields: season number, episode number, episode title
- Parse `S01E02`, `1x02`, `Season 1 Episode 2` and similar from filenames
- File as `TV/Show/Season 01/Show - S01E02 - Episode Title.ext`
- TMDB episode lookup to fill the title when the filename only gives numbers
- An episode belongs to its series, so a show is one item with many episodes
  rather than many unrelated rows

**Out:**

- Specials and multi-episode files (`S01E01-E02`). Recognised and left in the
  inbox rather than filed wrongly — the same rule the organizer already
  follows for anything it cannot place confidently.
- Anime absolute numbering. A separate convention; worth doing only if the
  library gains anime.

## Approach

1. Migration: `season_number`, `episode_number`, `episode_title`, and a
   `parent_id` linking an episode to its series.
2. An `EpisodeParser` handling the common filename shapes, returning null
   rather than guessing when nothing matches.
3. `showSegments()` builds the real path; without a season and episode number
   the file stays in the inbox, matching how movies behave without a year.
4. TMDB fills the episode title from series id plus season and episode.
5. The scanner recognises an episode and attaches it to its series, creating
   the series row if it is new.

## Constraints

- **Zero-padded season and episode numbers.** `S1E2` sorts after `S1E10` in
  every file browser; `S01E02` does not.
- Filed only on an exact match, like every other type. A wrongly-numbered
  episode is worse than an unfiled one, because it looks correct.
- The naming convention has to stay readable by Plex, Jellyfin and Emby, which
  all expect `Show - SxxEyy`.

## Verification

- A folder of `Show.S01E01.mkv` … `S01E10.mkv` files as ten distinct episodes
  in `Season 01`, in order.
- `1x02` and `Season 1 Episode 2` parse to the same place as `S01E02`.
- A file with no recognisable numbering stays in the inbox.
- Episodes group under one series rather than ten unrelated rows.
- Existing movies, music and books file exactly as before.

## Status

Not started. Blocks `Uploads.md` — uploading a season before this lands
produces a folder of colliding filenames.
