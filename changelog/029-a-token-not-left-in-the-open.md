# 029 — A token not left in the open

**Merged** 2026-08-23 · **Issues** S-105, GitHub #46

`GET /api/v1/transfer/requests/5` returned a live bearer token to anyone who
asked. No authentication, ids sequential from 1, and the token grants read of
the entire library — catalogue, manifest, every file.

Found by `a5` while checking why progress reports were not landing.

## What changed

### The receiver proves it is the machine that asked

`show()` cannot be authenticated: collecting the token is *how* a receiver
authenticates. So the receiver now generates a 32-byte secret before there is
anything worth stealing, sends it with the request, and presents it to collect
the token.

The source stores only a SHA-256 of it. The plain value never leaves the
machine that invented it, so the column is worth nothing to anyone reading the
database.

Compared with `hash_equals()`, so neither the value nor the time taken to
reject it says anything.

**Without a matching claim the endpoint still answers** — with the state, and
no token. An unapproved request discloses nothing either way, which is the
property the original design was protecting.

### It does not invalidate a transfer already running

A request with no `claim_hash` is one made before this existed and is answered
as it always was. That exception empties itself, because `store()` records a
claim for every new request.

This mattered concretely: a 46.3 GB copy was 786 files in when the fix was
written, and a change that invalidated its request would have cost the lot.

## Worth knowing

- **Two nullable columns**: `transfer_requests.claim_hash` and
  `transfers.claim`. Nothing dropped or rewritten.
- The exposure was wider than first reported. Tailscale Funnel proxies `/`,
  so this was reachable from the public internet rather than only the tailnet.
- `poll()` has exactly one caller — the admin page's "Check for approval"
  button. `RunTransferJob` uses the token already on the row, which is why a
  running copy is unaffected. Traced independently on both machines.
