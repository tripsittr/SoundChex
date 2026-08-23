# 017 — Which copy of a duplicate survives

**Merged** pending · **Issues** S-95 (part) · GitHub #16, #18

Groundwork for the music cleanup. The deletion tooling already existed and was
better than what I started to write; what was wrong was which file it kept.

## What changed

### The filed copy wins, whatever order they arrived in

`DuplicateDetector::check()` picked the original by lowest id — "the older row
is treated as the original purely because it was catalogued first, with
identical content there's no better criterion".

There is a better criterion. The organiser puts a copy in `media/library/`
deliberately, and the rest of the catalogue points into that tree. A loose copy
catalogued first would survive while the filed one — the one everything else
expects to still be there — was deleted.

That matters directly for what was asked in #16: the loose copies under
`media/unsorted/Sunnify/` are the ones to go, and under the old rule roughly
half of them would have won on age instead.

Both separators are matched, because the organiser writes `media\library\…` on
Windows (S-86). Matching only forward slashes would have made every Windows
copy look loose and the preference do nothing on the machine it was written on.

### A command that already existed

`library:duplicates` has been there all along — it hashes, flags, lists, and
`--merge` removes with `DuplicateDetector::merge()`, which re-hashes both files
immediately before deleting either and refuses if they have diverged. It also
keeps the duplicate *row* and repoints it at the surviving file, which is
exactly the "point the survivor at playlists" asked for on #16.

I had written a second command before finding it. Deleted unshipped. The
existing one needed no help; the keeper rule did.

## Worth knowing

**Still nothing deleted, and nothing can be yet.** Only ~918 of 9,737
catalogued files are on this machine, and `media/unsorted/Sunnify/` — where the
1,064 duplicate hashes live — is not here at all. Those hashes were measured in
the *imported* catalogue, so they describe the source's library. The cleanup
runs on the Mac, or here once the transfer finishes.

## Tests

**436 PHP · 87 Vitest.** 3 new.

**The first version of these tests could not fail.** Each set up one duplicate
candidate, and with a single candidate `first()` returns it whatever the
ordering rule is — deleting the preference entirely left all sixteen green.
They now set up two candidates, filed and loose, so the rule has an actual
choice to get wrong. Removing it turns two red.

That is the fourth test today that needed breaking before it was worth
anything. The pattern is consistent enough to be worth naming: a test written
against a *fix* tends to reproduce the fix's own assumptions, and the only
reliable check is to delete the code and watch.

One of them also failed for a reason unrelated to what it tested —
`content_hash` is deliberately not in `$fillable`, so `create()` dropped it
silently and both rows ended up unhashed. Set with `forceFill` now, as the rest
of the codebase does.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86.
