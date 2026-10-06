# The queue worker watched the wrong queues

Tracker #496, found by install testing on the live server.

The Server app's dashboard said **"36 waiting, 8 failed. Nothing is being worked
on right now — the worker may be idle between jobs, or stopped."** The worker was
neither idle nor stopped. It was running, healthy, and watching queues that had
nothing in them.

Phase 2 of the pipeline rebuild gave each stage its own queue — `io` for hashing
and moving bytes, `cpu` for probing, `net` for anything waiting on somebody
else's server, `default` for the transitions. `src-tauri/src/supervisor.rs` was
updated to pass `--queue=default,net,cpu,io`. **The launchd and systemd templates
were not**, and a bare `queue:work` serves `default` only.

So on this machine every `RunPipelineStageJob` landed on `net` and sat there. 36
of them, the oldest three hours old, behind a worker that reported itself fine.
That is the worst shape for a bug like this: nothing crashes, nothing logs, and
the dashboard's own explanation ("the worker may be idle or stopped") points away
from the cause.

Both templates now pass the same list as the Rust supervisor, cheapest queue
first so a quick transition is never stuck behind byte-moving, with a comment in
each saying the three must not drift.

**Verified on the live install**: patched the running LaunchAgent, reloaded it,
and the backlog went 36 → 7 → 0 inside 30 seconds.

## Also fixed while testing the install

**`APP_URL` pointed at `:8443`, and that made every advertised address
unreachable.** The dashboard read "Fastest route: None / No address answered /
Addresses answering 0 of 3 / Clients cannot reach this server".

`NetworkAddresses` takes its port from `config('app.url')`, so one wrong value
poisoned all three addresses at once. Caddy serves `*:8000`; nothing listens on
8443. And the hostname does not help either — public DNS for
`el-laptop.tail7e590c.ts.net` returns only the Funnel relay IPs
(`208.111.34.11`, `208.111.35.209`), never the tailnet address, and Funnel relays
serve `:443` only, so a TLS handshake on `:8443` dies with `SSL_ERROR_SYSCALL`.
Pinned to the tailnet IP with `--resolve`, the same `:8443` URL answers 302 — the
port works, the name resolution is the fault.

Set to the `:443` Funnel host, which is the one address reachable from the LAN,
the tailnet and outside. `network:probe` now reports **3 of 3 answering** (LAN
60ms, tailnet 125ms, Funnel 509ms).

The OAuth redirect was already correct and is unaffected: the Playlist Porter
plugin stores an operator override, which is exactly why S-322 added one.

**The 8 failed jobs were stale.** Every one was `EnrichMediaItemJob` failing with
SQLite `database is locked`, dated 21–22 Sep. WAL is on with a 120s busy timeout
now, so the condition is already mitigated; the rows were from before that.
Flushed.

**Six items were hidden with nothing saying why.** Ran `library:backfill-review`:
all six classify as `compilation_or_undated` — the complaint that started the
whole rebuild — plus one "no album" and one "file missing". They now appear in the
review queue with a reason instead of being invisible.

`server:health` now reports **Server is healthy**.

## Still broken / not done

- **The installed app can be two weeks stale and look current.** The
  `Server.app` payload is a snapshot of the Laravel app taken at bundle time. The
  one in `/Applications` was built **Sep 22** and carried neither the merged
  Phase 1–8 rebuild nor this branch. Nothing warns you. Worth a version check on
  the dashboard; tracked separately.
- `npm run build:server` fails at the **DMG** step (`bundle_dmg.sh`). The `.app`
  itself builds and installs fine, so this only blocks producing an installer
  image.
- **Test-factory rows in the dev database** (#495): 10 `@example.*` users and 6
  items under `storage/framework/testing/`. Deleting them was refused here as an
  irreversible operation, so they are left for the owner.
