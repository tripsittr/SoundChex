# 084 — Bundled server Step 0: Caddy → php-fpm proven by hand (S-151)

*2026-09-18.*

## Done

Proved the target runtime topology for the bundled production server — the app
running behind **Caddy → php-fpm**, bypassing `artisan serve`, with the upload
cap finally ours to set.

- **Caddy → php-fpm, working end to end.** `/` → 302 `/login`; `/login` → 200
  with real Laravel HTML + a CSRF token (sessions live); `/app` → 302 to login
  (auth middleware intact); `/build/manifest.json` served **directly by Caddy**
  as a static file, never touching PHP.
- **The 25 MB cap is real and enforced both ways.** A 5 MB POST body sailed past
  the size gate (419 only at CSRF) where `artisan serve` dies at 2 MB — this is
  the direct cure for the playlist-cover 422. A 30 MB body is rejected 413 by
  Caddy before PHP sees it. Effective config through the stack: `sapi=fpm-fcgi`,
  `upload_max_filesize=25M`, `post_max_size=26M`.
- **The PoC is meaningful, not a stand-in.** The `php-fpm` used is Herd's
  `php84-fpm`, which is itself **static-php-builder output** (`--enable-static`,
  NTS) with every required extension present — the same static-PHP-CLI toolchain
  Step 1 calls for. So this proves a static `php-fpm` runs the app correctly.

## Notes

- Recipe (exact Caddyfile, php-fpm pool config, PHP build provenance, and the
  gotchas) is recorded in `Documentation & Planning/BundledServerTasks.md` under
  Step 0. Gotchas worth carrying forward: use `caddy run` not `caddy start`
  (hangs); use a **TCP** FPM listener not a unix socket (104-char path limit,
  and Windows has no unix sockets anyway); Caddy's `request_body max_size` is the
  hard 413 gate, before PHP.
- Nothing shipped in the product yet — Step 0 is a hand-run proof. Next is Step 1
  (produce our own per-platform static PHP + php-fpm build with the pinned
  extension set and a `cacert.pem` beside the binary).
- The PoC ran on a throwaway port/socket and was torn down; the live `artisan
  serve` on :8000 was never touched.
