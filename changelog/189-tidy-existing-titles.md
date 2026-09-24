# 189 — Titles already filed lose their credits

**Merged** 2026-09-24 · **Issues** S-365

Title cleanup runs during enrichment, so it only ever touched tracks added
after it worked — and it was switched off entirely between S-321 and S-361.
Everything filed in between kept whatever its tagger wrote. `music:tidy-titles`
goes back over those.

## What changed

### A backfill command

`php artisan music:tidy-titles` lists what it would change; `--force` applies
it. `--limit` takes a quick look at part of the library.

Only the `title` column is rewritten. The file on disk keeps its name: renaming
is filing's job, and a rename that fails halfway is worse than a title that
reads oddly in a file manager.

### A title is never replaced by its own credit

Found by reading the dry run before trusting it. `Adiemus - Karl Jenkins, …,
Adiemus, …` is a real title followed by its artist list, and that list names a
band called Adiemus — so the prefix matched, and stripping it would have kept
the credit and thrown the title away. A remainder made only of artist names now
vetoes the strip.

Names are compared on letters and digits alone, because the same person is
"Jody K. Jenkins" in the artist field and "Jody K Jenkins" in the title.

## Worth knowing

- **Run on this machine's library: 50 titles rewritten**, after a backup
  (`soundchex-2026-09-24_172341.sqlite.gz`). A second run reports nothing to
  do.
- Reversible from that backup. There is no undo in the command itself —
  the original title is not kept anywhere once overwritten.
- Files on disk still carry the old names. They will pick the tidy name up
  whenever filing next moves them.

## Still wrong

The command reads the whole music library into memory as a change list before
writing. Fine at this size; it would want chunked writes for a library an order
of magnitude larger.

## Tests

PHP · `tests/Feature/TidyExistingTitlesTest.php` 4/4 — dry run changes nothing,
`--force` strips, a clean title is untouched, and a title is never replaced by
its own credit. `tests/Feature/TitleTidierTest.php` 20/20.

Full suite 945/950, 4 skipped. The one failure, `ListPageCostTest`, is
unrelated and reproduces on a clean tree — S-362.
