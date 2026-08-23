# 019 — A transfer that stops when it is no longer allowed

**Merged** pending · **Issues** S-100

Transfer 9 sat reporting itself as `running` for half an hour having copied
nothing. The worker was alive. The transfer was fine. It was simply no longer
allowed to read anything:

```
#24958  attempts:3  The server answered 401.
```

## What changed

### A 401 is not about one file

Transfer tokens last four hours from approval. When one expires mid-copy every
remaining file answers 401 — and the receiver treated each as *that file*
failing, marked it, and moved to the next.

So a dead token was retried through **6,964 remaining items**, none of which
could ever arrive, while the transfer reported itself as running the whole
time. The only signal was a stall detector noticing that nothing had moved.

401 and 403 now end the transfer with an error that says what to do: ask again
and approve it on the other machine. Nothing about it is retryable — a person
has to say yes — so grinding is worse than stopping.

What has already copied is left exactly as it is, so a fresh approval resumes
rather than starting over. 46 GB is not something to fetch twice.

An ordinary failure — 404, a truncated response, a server hiccup — still fails
only that file. Stopping a whole transfer over one missing file would be a far
worse bug than the one being fixed, and that case is tested.

## Worth knowing

**This is what stopped the real transfer**, at 14 files of 6,995. The token was
approved around 04:00 and expired around 08:00. A new request needs approving
on the MacBook before it can continue.

The watcher already checks whether the request is still approved — but only
once the transfer is `failed`, and until now item-level 401s never made it
failed. With this fix the two meet: the transfer stops, the watcher checks, and
it reports that the request needs approving again rather than silently retrying.

## Tests

**446 PHP · 87 Vitest.** 4 new.

Removing the guard turns two red. The refusal case — an ordinary 404 must not
stop the transfer — is tested as hard as the success.

**Still failing, and not from this change:** 4 failures and 1 error, all S-86.
Down from six: two were fixed by 018.
