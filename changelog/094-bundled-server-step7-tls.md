# 094 — Bundled server Step 7: TLS reconciliation (S-151)

*2026-09-18.*

## Done

Settled how the bundled Caddy handles TLS, in three modes, and made the bundled
`Caddyfile` support all three:

1. **Behind a TLS proxy (default)** — `tailscale serve` / SCNet / a reverse proxy
   terminates HTTPS and forwards to Caddy's plaintext listener. Same shape as
   today; Caddy just replaces `artisan serve`.
2. **Direct with a real domain** — remove `auto_https off`, set the site label to
   the domain, Caddy provisions Let's Encrypt.
3. **Direct on a LAN, no domain** — `tls internal` serves Caddy's local CA cert.

## Fixed

Without a `trusted_proxies` option, Caddy's `php_fastcgi` passes its own
(plaintext) scheme to PHP, so the proxy's `X-Forwarded-Proto: https` was ignored
and the app would generate `http://` URLs behind TLS. Added
`trusted_proxies static private_ranges 100.64.0.0/10` (LAN ranges + Tailscale's
CGNAT range) to the bundled Caddyfile.

## Verified (Docker)

A request carrying `X-Forwarded-Proto: https` through the bundled Caddy now
reaches PHP as `https` (HTTP 200) — so `trustProxies` + `SetAppUrl` generate
`https://` URLs. `RemoteAccess.md` updated with the bundled-server note.
