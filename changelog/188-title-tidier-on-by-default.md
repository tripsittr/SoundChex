# 188 — The title cleanup is on again

**Merged** 2026-09-24 · **Issues** S-361

Title cleanup has not run on any install since S-321. The app's own cleanup
was moved into a bundled plugin, S-321 made every plugin opt-in, and the
cleanup went opt-in with it — so `metadata.title` had no filters, the job that
tidies a title before filing quietly did nothing, and every track kept whatever
its tagger wrote.

## What changed

### A manifest can ask to start enabled

`plugin.json` now takes `enabledByDefault`. The bundled Title Tidier sets it,
because it is not a third-party addition — it is the app's default behaviour
that happens to be packaged as a plugin.

The flag is read **only when the install row is created**. Once a plugin is
installed, the operator's choice is the one that counts: turning the tidier off
keeps it off across restarts and re-scans.

### Existing installs are switched on once

A migration enables the tidier where it was never deliberately turned off —
matched on a row whose `updated_at` has not moved since `created_at`, which is
the default nobody chose. A row an operator has touched is left exactly as they
set it.

The migration is deliberately irreversible: rolling it back would discard a
choice to keep the tidier on rather than restore a prior state.

## Worth knowing

- **Migration included, and it has been run on this machine's library.** The
  bundled Title Tidier is now enabled here.
- This only changes what *future* enrichment writes. Titles already on disk
  keep their credits until a cleanup pass runs over existing records — not part
  of this change.
- Anyone who wants the old behaviour can disable the plugin; that choice now
  survives.

## Still wrong

Nothing found. The gap this closes existed for two days without anyone
noticing, because the test that should have caught it was failing and had been
written off as a pre-existing failure — see Tests.

## Tests

PHP · `tests/Feature/PluginLoaderTest.php` 13/13, two new: a manifest asking to
start enabled lands enabled, and that default never re-enables a plugin someone
turned off.

`tests/Feature/EnrichmentWritesCreditsTest.php` 6/6, up from 5/6. The failing
test was real: it asserts the job's actual contract, and it had been failing
since the tidier moved behind an opt-in plugin. All three tidying tests now
register the filter the way the shipped plugin does — without that the other
two were passing vacuously against an empty filter chain.

Full suite 940/945, 4 skipped. The one failure,
`ListPageCostTest::test_an_album_page_does_not_query_per_track`, is unrelated
and reproduces on a clean tree — logged as S-362.
