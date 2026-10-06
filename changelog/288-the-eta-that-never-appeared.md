# The ETA that never appeared

Follow-up to #291, found by driving the merged widget on the live dashboard
rather than trusting that it worked.

The queue widget showed **"about 47 a minute"** and never said how long that
would take. The rate was right; the ETA beside it was always blank.

## The memo was real and never applied

#291 memoised `throughput()` per request, precisely so that asking twice in one
render would not write a second sample and compare against one zero seconds old.
That fix was correct — and useless, because `QueueInspector` was **not bound as
a singleton**. Every `app(QueueInspector::class)` built a fresh instance with an
empty memo, so the widget's `throughput()` and `remaining()` were two separate
measurements and the second always returned null.

Bound per request now, with the same reasoning as `CurrentProfile` directly
above it — which carries a comment about twenty call sites each building their
own instance and re-running the same query. Same bug class, same fix, four lines
apart.

## The test that missed it

#291's test asked the *service* twice, which passes either way once one instance
is reused. The new one goes through the **widget**, which is where the two calls
actually come from — and fails when the binding is removed.

That is the distinction worth keeping: a test that exercises the service proves
the memo works; only one that exercises the caller proves the memo is reached.

## Verified

- **14 tests pass**; one new, checked by removing the binding.
- On the **live queue**: rate `13.3`/min and `6 hours` remaining, both present.
  Before the fix the same call returned `46.7` and `NULL`.
