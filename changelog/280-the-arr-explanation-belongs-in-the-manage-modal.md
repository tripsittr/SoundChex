# The acquisition explanation belongs in the manage modal

Reported: *"This section should not be here. we should just have this stuff in
the manage page for the arr stuff."*

A banner led the Integrations page whenever no acquisition app was running —
which is **most installs**. So the first thing on a page about connecting outside
services was an optional, not-installed, separate set of applications explaining
what they are and how to start them, above the integrations somebody actually
came to configure.

The same text now appears inside an app's own manage modal, and only when that
app is not up. Someone who clicks into Radarr wants to know why it is not
answering; someone configuring a TMDB key does not.

A running app is not told how to start itself, which the banner had no way to
express — it was all-or-nothing across the whole page.

## Removed two dead methods

- `anyRunning()` — its only caller was the banner.
- `startCommand()` — already unreferenced before this change, and it had
  **drifted**: it returned `docker compose -f docker/arr/compose.yaml up -d`
  while the banner printed `php artisan arr:setup --start`. Two answers to one
  question, one of them unreachable.

## Two tests changed rather than deleted

`test_the_page_renders_when_nothing_is_running` and
`test_a_running_app_shows_its_version_and_queue` both asserted on the banner
text. Their real subjects are "a thrown probe must not 500 the page" and "a
running app shows its version", so they now assert that instead of the string
that moved.

## Verified

- 1557 passed, 3 skipped, 0 failed (whole suite bar `WorkerLogTest`, which OOMs
  on this machine regardless of branch).
- Three new tests, **all three verified to fail** against the old view by
  reverting only the Blade file: the banner absent from the page, the explanation
  present when managing a stopped app, and absent when managing a running one.
