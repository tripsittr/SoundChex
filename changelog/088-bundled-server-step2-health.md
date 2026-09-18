# 088 — Bundled server Step 2: PHP extension health check (S-151)

*2026-09-18.*

## Done

A wrong PHP build disables features silently — tag reading returns nothing,
HTTPS fails deep in a job, image work throws. Step 2 makes that visible.

- **`RuntimeHealth`** service — the single source of truth for the required and
  recommended extension sets, each with *why it matters*, kept in step with
  `composer.lock` and the bundled build flags.
- **`php artisan server:check-extensions`** — asserts the required set, names
  what's missing and what it breaks, exits non-zero so a supervisor can refuse
  to start. Recommended-but-absent extensions (pcntl, gmp, opcache, sodium) are
  warnings, not failures.
- **Wired into the Filament Services page** — a "PHP runtime" section shows green
  when healthy, or lists the missing extensions in red beside the services.
- **Scheduled daily** (`--quiet-ok`) so a host that swaps PHP under a self-hoster
  shows up in the log as a named missing extension.
- Tests with teeth: a simulated missing `exif`/`curl` makes both the service and
  the command fail *and name it*; a missing recommended extension does not fail.

## Notes

- The suite has **3 pre-existing failures** unrelated to this change
  (`LibraryAdministrationAccessTest` ×2 → 404 on content pages;
  `IntegrationsPageTest` content assertion). Verified they fail identically on
  clean `main` with this change stashed. Worth a separate look — logged to fix
  under the polish pass.
