# 182 — A cleanup pass over Http, Console, Models and Jobs

**Merged** 2026-09-23 · **Issues** S-358

The last of the PHP cleanup: ~14,200 lines across four directories. Nothing
about how the app behaves changes, and no API response changes shape.

## What changed

### Two constants that had to agree, and one that had already drifted

`MediaCenterController` and `Api/MediaController` both defined
`LISTENED_MAX_STEP = 90`, and both docblocks claimed to mirror the other. The
completion threshold had already come apart: a named constant in the API
controller against a bare `0.95` in the web one. Left alone, a drift there
means one front end calls a film watched while the other offers to resume it.
Both live in one place now.

### A field list that had to agree with itself

`AdminController::editableMeta()` and `::updateMeta()` each carried a per-type
list of editable fields, plus a third `match` for the relation. A field in one
list but not the other is readable-but-unsaveable — silent, and only visible
to whoever hits it. One list now, matching exhaustively on the enum, with
`MediaItem::metadata()` supplying the relation.

**The JSON was verified byte-identical for all four media types**, key order
and the null-metadata case included, before and after.

### A dead condition in an authorisation check

`EnsureApiAdmin` and `Api/TokenController` both asked
`isOwner() || canAdministerLibrary()`. `can()` already returns `true` for the
owner, so the first half never decided anything. Removed, with the
short-circuit it depends on named in a comment rather than left implicit.

### Four more orphaned docblocks

The defect found across `app/Services` (11) and `app/Filament` (2) is here too:
in `EnrichMediaItemJob`, `Transfer/RequestController`, `Transfer/SourceController`
and `Profile`. In each case a method's reasoning sat above a *different*
method's docblock, leaving the real method undocumented — including
`Profile::can()`, whose comment explains why a permission check that fails open
is worse than none.

## Worth knowing

- Tests: **903 of 909**, the same two pre-existing failures.
- Net **+28 lines**: the duplication removed was dense, and the comments now
  explaining why these values must agree are not.

## Still wrong

- **S-359** — `db:backup` leaks a handle and strands the uncompressed snapshot
  when exactly one of its two `fopen`/`gzopen` calls fails. `prune()` globs
  `*.sqlite.gz` only, so it can never reap the orphan: on the daily schedule
  every such failure permanently keeps a full-size copy of the database, which
  is what `prune()` exists to prevent. Latent — no stranded files on this
  machine — but it wants its own change and a test.
- The `($n === 1 ? '' : 's')` idiom appears 21 times across 11 console commands
  where the project's own idiom elsewhere is `str('word')->plural($n)`.
  Converting all 21 is churn in files with no other reason to change.
- `format()` / `mimeFor()` / `READABLE` are duplicated between the web and API
  `ReaderController`s. Deduplicating means moving code between namespaces that
  are deliberately separate contracts.
