# 010 — A cancel test that can pass

**Merged** 2026-08-22 · **Issues** S-87

The browser test for cancelling a transfer could not have passed as written. It
asked for a button the page deliberately does not offer in the state the test
put it in. The page was right; the test was wrong.

## What changed

### The test asks a server that answers

Cancelling was tested against `http://127.0.0.1:9`, an address chosen because
nothing listens there. But asking a server that refuses the connection leaves
the transfer `failed`, and Cancel is not offered for a failed transfer — there
is nothing left running on the other machine to stop. The click then waited the
full 30 seconds for a button that was never going to render.

This server cannot stand in either. `artisan serve` is single threaded, so a
request it makes to itself waits on the process already busy serving the page
that made it, and times out after 20 seconds.

So the suite now runs a small stub on port 8198 that answers any POST with a
request id, which is all asking looks at. The transfer reaches `requested`, and
`requested` is cancellable. The test now finishes in about 5 seconds.

### Rows are addressable, and assertions are scoped to one

Every step matched text across the whole page. Transfers are not cleaned up
between runs, so the closing check that the row is gone was counting rows left
behind by earlier runs and could never reach zero.

Each transfer row now carries a `wire:key`, and every assertion is scoped to
the one row the test created. The key is worth having on its own account: a
keyless `@foreach` lets Livewire reuse the wrong DOM node when several rows
look alike, which is exactly what a list of near-identical addresses is.

## Worth knowing

- No migrations, and nothing written to any database.
- The stub server starts and stops with the suite, like the other two.
- The test was checked for teeth by breaking the Cancel button, which turns it
  red — it is exercising the button, not passing regardless.
- It confirms in a browser what shipped in 008 unverified, because the machine
  it was written on has no `.env.e2e` and could not run Playwright.
