# 092 — Bundled server Step 4: cross-platform supervision (S-151)

*2026-09-18.*

## Done

A supervisor that starts the **bundled** Caddy + php-fpm + queue + scheduler by
relative path — no Herd, no system PHP — and keeps them alive across crashes and
reboots. New home: `server/supervisor/` + `server/scripts/install-services.sh`
(and `uninstall-services.sh`).

- **macOS (launchd) — verified natively.** From a locally-built bundle, installed
  test services; Caddy → php-fpm served **HTTP 200 `sapi=fpm-fcgi`**, and killing
  php-fpm had launchd restart it (new pid) with the stack still serving. Live
  services untouched.
- **Linux (systemd) — verified via Docker.** All four unit templates render with
  bundled paths (no leftover placeholders); the stack boots and serves **200
  `sapi=fpm-fcgi` upload=25M** in Debian (also Ubuntu/Alpine/Fedora — the static
  musl build is distro-portable).
- **Windows — authored, to verify on real hardware.** `install-services.ps1`
  registers queue + scheduler as services (bundled `php.exe`, restart-on-crash
  via `sc.exe failure`) and installs Caddy *if* a `php-cgi.exe` is bundled. It is
  not yet — Windows has no php-fpm SAPI, so the HTTP front needs a php-cgi build
  added to the Windows bundle (a follow-up). The script warns clearly.

## Fixed

The runtime `php-fpm.conf` lacked `user`/`group`, so php-fpm refuses to start
when a system service runs its master as root. Added them (filled by the
installer; ignored harmlessly when the master is already unprivileged).

## Still open

- Reconcile `HostServices.php` (still copies the old Herd-pathed plists) to these
  bundled templates — deferred to Step 5, where the install path is concrete.
- Windows HTTP front needs a bundled `php-cgi`. Verify the Windows services on
  the owner's Windows Server.
