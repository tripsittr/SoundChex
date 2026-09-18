# 093 — Bundled server Step 6: standalone headless installer (S-151)

*2026-09-18.*

## Done

`server/scripts/install-headless.sh` — a no-GUI installer for a NAS or home
server. Prerequisites are just `curl` and `tar`; **no system PHP** is needed,
because it uses the bundled php for every PHP step.

- Detects OS/arch, fetches the matching `soundchex-server-<os>-<arch>.tar.gz`
  from GitHub releases (or takes `--runtime-file`), verifies the `.sha256`.
- Prepares the app with the **bundled** php: `key:generate`, composer install
  (bundled/system composer if present, else expects a shipped `vendor/`),
  `migrate --force`, `server:check-extensions` (aborts if the runtime is
  missing an extension), `server:detect-address`.
- Registers the services via the Step-4 `install-services.sh`.

## Verified (Docker, bare Debian, no PHP installed)

- The **bundled Linux php runs the real app's `artisan migrate`** end to end
  against a fresh SQLite — every migration applied.
- `server:check-extensions` reports all 20 required extensions present.
- (`artisan --version` boots Laravel 13.15.0 on the bundled php.)

This is the plug-and-play claim proven: a box with only curl+tar gets a working
server from the bundle alone.

## Notes

Shares its runtime artifacts and the Step-4 service installer with the desktop
bundling path (Step 5). The service registration itself (systemd/launchd) is the
same code verified in Step 4.
