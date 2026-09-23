# 181 — A cleanup pass over `app/Filament`

**Merged** 2026-09-23 · **Issues** S-356

Behaviour-preserving simplification of the admin panel — 74 files, ~9,700
lines. **−212 lines net.** Nothing about how the panel behaves changes.

## What changed

### Four edit pages that were the same page

`EditMusic`, `EditBook`, `EditShow` and `EditMovie` each carried a verbatim
copy of the same `mutateFormDataBeforeFill` / `mutateFormDataBeforeSave` /
`handleRecordUpdate` / `getHeaderActions` quartet, differing only in the
relation name and the field list. Both are abstract methods on a new
`EditsMediaMetadata` concern now; each page declares only what makes it
different.

The copies had already begun to diverge in style — `EditMusic` built its
notification over several lines where the other three used a one-liner, for
identical behaviour.

### A status pair that has to agree with itself

`[NeedsReview, Failed]` appeared **twice in each of four list pages** — once
for the tab's badge count, once for its filter — plus four more times across
the Duplicates resource. A badge reading 3 above a tab showing 5 is a bug
report, so these must agree by construction rather than by care.
`HasNeedsReviewStatuses` and `HasNeedsReviewTab` make that so.

### The review hub, 939 → 865 lines

The duplicate and metadata tables both filter the same column and must offer
the same four media types; that options array was written out twice. The cover
column — including its `placehold.co` fallback URL, which must match across
both tabs — was written twice. The `is_file`-then-`filesize` dance appeared
four times and `/ 1048576` four times. All shared now.

The single and bulk versions of "re-enrich" and "mark reviewed" had identical
bodies; the S-302 reasoning that explains *both directions* of the review rule
— cleared on an explicit re-enrich, respected on a later one — is preserved on
the two extracted methods.

### Two more orphaned docblocks

The defect found in `app/Services` (S-353) is here too: in `Plugins.php` the
docblock for `loadError()` had drifted above `syncStyles()`, leaving
`loadError()` undocumented; in `Dashboard.php` two stacked docblocks both
described `requiredPermission()`. Both repaired.

## Worth knowing

- **A 0-byte file still reports "0.0 MB", not "—".** The extracted helper uses
  `$bytes === false ? null : $bytes` rather than `?: null` for exactly this
  reason; it is verified.
- Tests: **903 of 909**, the same two pre-existing failures. Every admin page
  and the three refactored edit pages were also rendered and return 200 —
  Filament breakage does not always reach the suite.

## Still wrong

- **`MusicTable` is a near-verbatim copy of `BuildsMediaTable::baseTable()`**,
  which Books, Movies and Shows all use. It was *not* folded in: its genre
  filter loads options across every media type where the trait scopes to one,
  and it lacks the convert/history/subtitle actions and transcode polling.
  Converting it would change which options appear in a dropdown and add
  actions to rows — semantic, not simplification. It is the largest remaining
  duplication in this directory and deserves its own issue.
- **S-357** — disabling a plugin whose folder has been deleted leaves its
  compiled stylesheet on disk, because `syncStyles()` resolves the directory
  before branching and returns early when it is gone. Harmless (the route
  gates on `enabled`), but it contradicts the intent stated in the code.
- Filament v5 deprecations in `DuplicatesTable` (`ImageColumn::size()`,
  `Action::infolist()`) are real but are an upgrade, not a cleanup.
