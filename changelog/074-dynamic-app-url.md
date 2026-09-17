# 074 — Generated URLs match the request's address

**Merged** 2026-09-17 · **Issues** S-147

Links the server generates — artwork, stream, redirects — now use whichever of
this machine's addresses the request actually arrived on, instead of the single
one in `APP_URL`.

## What changed

`SetAppUrl` middleware reads the request's scheme + host (+ non-default port) —
the real ones behind the relay, since `trustProxies` is on — and sets `app.url`
and the URL generator's root to them for that request. Registered on both the
web and API stacks (the API returns stream and artwork URLs), prepended so the
root is corrected before anything downstream builds a URL.

Before, a page reached over the LAN that generated a Funnel link sent every
asset and redirect the long way round, or to an address the device could not
use — the cost of a single static `APP_URL` on a server reachable three ways.

## Worth knowing

- This is the per-request half of the address fix. The `.env` `APP_URL` still
  matters for the **queue and Artisan** (emails, jobs — no request to read), and
  `server:detect-address` (PR #73) keeps that value pointed at a real address.
  The two are complementary.
- No migration, no config change; the middleware overrides config per request.
