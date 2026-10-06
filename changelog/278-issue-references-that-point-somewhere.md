# Issue references that point somewhere

Tracker #489.

156 docblocks across 97 files cited issue numbers — `#454`, `#465`, `#470` and
twenty-three others — that **resolve to nothing**:

- the app repo has **no issues at all**; those numbers are PR numbers, and only
  #269–#281 exist
- the live tracker ran to **#403** before this rebuild was finally logged
- `LibraryPipeline.md`, the plan they appear to come from, never defines them

They were invented in the session that wrote the code, and every one of them was
a dead end for anybody trying to find out why a piece of code exists — which is
the entire purpose of citing an issue.

Remapped to the real items: the eight phases are all **#489**, and the stale
content-hash note is **#491**, which has its own item because it is still open.

## What this pass deliberately did not do

The first attempt also **thinned** repeated markers, on the reasoning that
`(#489)` seven times in one file is noise. It was: but several of those
citations were load-bearing sentence content —

```
 * (#464). And the read-only attribute is cleared first: `unlink()` will not
```

— and removing them left `*. And the read-only attribute...` fragments in five
files. Reverted and redone as a number remap only. A repeated correct reference
is merely verbose; a mangled sentence is worse than the problem being fixed.

Four sites did need a second touch: citations listing several distinct numbers
(`(#457, #475, #476)`) collapsed into `(#489, #489, #489)`. Those were collapsed
to one reference with a targeted pattern that cannot reach surrounding prose.

## The diff is wider than "a number remap"

a5's finding, and a fair one. Running `pint --dirty` over the touched files
folded a formatter pass into the same commit: one-line methods expanded
(`name()`/`priority()` in `Show/Tmdb.php`), `=>` alignment stripped from arrays,
concat spacing changed (`$root.'/'` → `$root . '/'`), and FQCN references
replaced with imported class names in several test files.

Measured: of **393** added PHP lines, **186** carry a citation — so about half
the diff is formatting. All of it behaviour-neutral, and a5 verified that
independently by normalising the whole diff and checking every changed code line
has a 1:1 semantic counterpart.

It should have been two commits. Saying so here rather than re-splitting it,
because rewriting 105 files again to separate them risks more than it buys — but
a reviewer told "only comments changed" would not expect method bodies to move,
and that was a misleading thing to have written.

## Verified

- 1554 passed, 3 skipped, 0 failed, plus 6 in `WorkerLogTest` run separately —
  it OOMs at the compiled 512M default when the whole suite runs in one process,
  on clean `main` as well as here.
- `php -l` on all 105 changed files.
- No `(#4xx)` in the 452–488 range remains in `app/`, `tests/` or `database/`.

## Note for anyone adding a citation

`track:issue` in the **SoundChex Website** repo writes to the **live** tracker
through `TrackerClient` and prints which database it wrote to. That repo's local
`database/database.sqlite` is a stale clone (28 Sep), so an item created by the
CLI will not be found there — check soundchex.app. Mistaking the local copy for
the real one is how a set of numbers came to be invented in the first place.
