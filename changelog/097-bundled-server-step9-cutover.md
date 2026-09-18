# 097 — Bundled server Step 9: live cutover off Herd (S-151)

*2026-09-18.*

## Done

The build machine (`el-laptop`) was migrated **live** from Herd's `artisan serve`
to the bundled runtime — S-151's final step, on a real working server.

- Unloaded `com.soundchex.serve` (Herd `artisan serve` :8000) and installed the
  bundled **Caddy + php-fpm + queue + scheduler** via `install-services.sh`; all
  four run bundled binaries by relative path. No orphaned `artisan serve`; :8000
  is served only by bundled Caddy → php-fpm. `/login` → 200.
- **DB untouched** — same sqlite file; bundled php and Herd php read identical
  data (`users=2` both). Safety backup + archived old plists taken first.
- **Self-healing confirmed live:** killing php-fpm had launchd restart it, app
  still serving 200.
- Fixed a **stale APP_URL** (`macbookair` — a renamed-away device) and set the
  app's Tailscale HTTPS front: `tailscale serve --https 8443` →
  `https://el-laptop.tail7e590c.ts.net:8443` (tailnet-only). Website stays public
  on Funnel :443. The app is private to the tailnet, the site is public — the
  correct split.

## Fixed

The bundled `Caddyfile` template's `root` and log paths were **unquoted**, so an
install path with spaces ("SoundChex App") broke Caddy's parser. Quoted now —
fixes both the installer and the Rust supervisor, which share the template.

## Still to confirm

The `:8443` Tailscale HTTPS front returns `000` to *local* curl — the known
loopback-through-own-tailnet quirk (a phone on the tailnet reaches it when local
curl can't). Verify from a phone. README / BuildingOnEachPlatform rewrite to
"install the product, done" remains as a doc task.

## S-151 complete

All ten steps (0, 1, L, 2–9) are done: the runtime builds on every platform, is
supervised on macOS/Linux, headless- and app-installable, TLS-reconciled, ships
full GPL ffmpeg, and this machine now runs it in production.
