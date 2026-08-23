# 014 — Ten seconds is not long enough over a relay

**Merged** pending · **Issues** S-92

Three failures in one real transfer were the same thing:

```
cURL error 28: Connection timed out after 10014 milliseconds
```

Ten seconds is Laravel's default connect timeout. Two of those three happened
while the source was up and thirty-one other files were arriving without
trouble, so the connection was slow rather than absent.

Both machines are on a **relayed** tailnet — `tailscale status` reports
`relay "lax"` rather than a direct route — and a relayed connection is slower
to establish. Thirty seconds now.

Only the connection. The request timeouts are unchanged: a server that has
accepted a connection and then gone quiet is a different problem, and should
still be given up on.

## Worth knowing

This was found while the transfer was stopped for an unrelated reason — the
MacBook went offline mid-copy, which `tailscale status` reported as
`offline, last seen 2m ago`. That is not this, and this would not have saved
it. The two are easy to confuse from the error alone, which is part of why the
timeout is worth raising: a genuinely absent machine and a slow handshake
produce the same `cURL error 28`.

## Tests

**419 PHP · 87 Vitest.** 1 new.

Putting the client back on the default turns it red. It asserts the option
rather than the behaviour, because observing the behaviour needs a server that
accepts slowly — which a test cannot conjure and a fake cannot represent. That
is a real limit of the test, stated rather than papered over.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86.

**Unverified:** whether 30 seconds is enough. It is a change made on three
samples, all at the ten-second boundary. If `cURL error 28` shows up again at
30,000 ms, the number is not the answer and the relay is.
