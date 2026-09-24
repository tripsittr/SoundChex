# 193 — Duplicate playlists can be folded together

**Merged** 2026-09-24 · **Issues** S-325

Importing the same playlist twice made a second playlist with the same name
and nothing said which was which. The porter now asks first (its own change);
this cleans up the duplicates already made.

## What changed

`php artisan playlists:merge-duplicates` lists what it would do; `--force`
applies it.

Per user, the oldest playlist of each name wins and the rest fold into it: its
own tracks keep their positions, anything only the duplicates held is appended
after them, and the emptied duplicates are deleted. Names are compared the way
a person reads them, so "Karaoke" and "karaoke " are the same playlist.

Grouping is on **user and name**, never name alone — two people are each
allowed a playlist called Karaoke.

One transaction per duplicate. A merge that failed half way would otherwise
leave the tracks copied and the duplicate still present, and the next run would
copy them again.

## Worth knowing

- **Run on this machine's library**, after a backup
  (`soundchex-2026-09-24_182022.sqlite.gz`):
  - `Bluegrass/Folk/Rock/Country Stuff` #15 kept, #22 folded in — 77 + 2 new =
    **79 tracks** (76 were already shared).
  - `Karaoke` #17 kept, #21 folded in — a perfect duplicate, so it stays at
    **20 tracks**.
  - A second run reports nothing to do.
- Reversible only from that backup: the deleted playlists are gone, and the
  command keeps no record of what it folded in.

## Still wrong

Only exact-after-trimming name matches are treated as duplicates. "Karaoke" and
"Karaoke (2)" are left alone — deliberately, since a suffix is as likely to be
a real second playlist as an accident.

## Tests

PHP · `tests/Feature/MergeDuplicatePlaylistsTest.php` 7/7, new: a dry run
changes nothing, the oldest keeps the name, folded tracks append rather than
restart, a perfect duplicate leaves the kept playlist untouched, names match
case- and space-insensitively, two users may each have the same name, and a
second run has nothing to do.

Full suite 965/970, 4 skipped. The one failure, `ListPageCostTest`, is
unrelated and reproduces on a clean tree — S-362.
