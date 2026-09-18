# 098 — Fix three stale test failures (S-72 fallout)

*2026-09-18.*

## Fixed

The suite had **3 long-standing failures**, all stale references left over from
S-72 ("Remove the redundant Metadata Sources page"), not real bugs:

- `LibraryAdministrationAccessTest` (×2) hit `/admin/metadata-settings`, a route
  removed in S-72 → 404. Repointed to `/admin/library-settings` (the same
  library-admin-reachable content page).
- `IntegrationsPageTest::test_metadata_providers_are_listed_but_not_editable_here`
  asserted a literal "Metadata" heading. S-72 folded metadata providers into
  this page grouped by media type ("Film & TV", "Music", …). Now asserts a real
  provider ("TMDB") and its group ("Film & TV").

## Result

The full suite is green: **592 passed**, 0 failed (was 589/3).
