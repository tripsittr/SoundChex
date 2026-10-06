# 261 — Resolving a duplicate leaves both rows honest

**Merged** 2026-10-05 · **Issues** #461

Four defects in duplicate resolution, each of which ended with a row pointing
at a file that was not there, or a file deleted that should not have been.

## What changed

### "Keep the duplicate" no longer orphans the original

`resolveKeeping(keepDuplicate: true)` deletes the **original's** file and only
ever updated the flagged row. The original kept a path to a file that was gone,
with no status: an unplayable row in the library, plays and playlist entries
pointing at nothing, and any *other* duplicate flagged against it permanently
unresolvable — `merge()` found no original and refused, which surfaced as a
confusing "original is missing" skip.

Both rows now point at the surviving file, which is what `merge()` has always
done for the copy it deletes. The loser's `content_hash` is cleared, as rule 2
requires on any path write.

### The filed copy is no longer made the duplicate of a loose one

`pickOriginal()` ranked the *candidates* and never the item being checked. So
checking an older, filed row against a newer loose copy made the **filed** row
the duplicate — and under `duplicate_action = auto` the filed copy is the one
deleted.

The pair is now ordered in `flag()`, which every detection funnels through, so
the fix lands once rather than at each of the eight `pickOriginal()` call sites.
A filed row is never recorded as the duplicate of an unfiled one.

### A loose match cannot be merged in bulk

`DuplicateMatch::allowsBulkResolution()` is new, and `false` for `Likely` and
`SameTitle`. Both are deliberately wide — a shared artist and title with a
*differing* album or length, or a shared title and year with no provider id at
all — and both exist to surface things worth a look, never to decide them.
Merging them from a "merge selected" over a page of rows deletes a different
song's or film's file. That is what `LibraryCleanup.md` ruled out, and what the
table did anyway.

The bulk action now skips them and says how many it skipped, so the outcome is
visible rather than silent.

### `duplicate_action = report` is enforced everywhere

The settings page promises duplicates are only ever listed — "never act, even
from the review screen". Only the automatic sweep honoured it; the table
actions and `library:duplicates --merge` did not.

`LibrarySettings::mayResolveDuplicates()` is now checked inside `merge()` and
`resolveKeeping()`, which is every path that deletes a duplicate's file,
including `resolveKeepingBest()` and the CLI. Enforcing it in the detector
rather than in each caller means a new caller inherits it.

## Worth knowing

- No migration.
- **Rows already orphaned by the old behaviour are not repaired.** A row whose
  file was deleted before this still carries a dead path. `library:reconcile-paths`
  finds them; a proper sweep belongs with the pipeline sweeper in #465.
- `report` mode now genuinely blocks resolution. If duplicates stopped being
  mergeable from the admin panel, check that setting first — previously it had
  no effect there.
