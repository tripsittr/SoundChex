# 024 — An approval that outlives the copy

**Merged** 2026-08-23 · **Issues** S-104, GitHub #34

Four transfers of the same library died reporting an expired token. The
approval was expiring mid-copy, and it was expiring because its clock started
before anyone approved anything.

## What changed

### The window runs from approval, not from the request

`expires_at` was set when the request *arrived* — `now()->addHours(4)` in
`RequestController` — and `TransferApprovals::approve()` handed the token
whatever was left of it.

So the four hours were shared between however long the request sat waiting for
a person to notice it and the copy itself. Approve a request that has been
pending three hours and the token is good for one. A 46.3 GB copy does not
finish in one hour, and when the token dies mid-run the remaining files are
lost with it.

The window now starts at the moment of approval.

### And it is sized against the job

`LIFETIME_HOURS` stays at 4 and keeps its original meaning: how long a request
waits for a person. A new `APPROVAL_HOURS = 12` covers the copy.

46.3 GB is about 2.6 hours at 5 MB/s, longer if the link falls back to a relay
or the run is paused overnight. Four hours left no margin for either.

## Worth knowing

- No migrations. `expires_at` already existed; it is written at a different
  moment and with a different value.
- Existing approved-but-expired requests are **not** revived. They stay expired
  and a fresh request is needed — which is the right answer, since the token
  behind them is genuinely dead.
- The test was checked by reverting the fix, which turns it red.
