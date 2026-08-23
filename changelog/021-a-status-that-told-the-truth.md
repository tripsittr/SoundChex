# 021 — An approval that had expired still called itself approved

**Merged** pending · **Issues** S-102

Caught by the fix in 019 working. The transfer stopped on a 401 and said so
properly — and then the watcher restarted it, because it asked the source
whether the request was still approved and was told **yes**.

## What changed

`TransferRequest::publicState()` returned `expired` only for a *pending*
request that had run out of time. An **approved** one past its four hours kept
reporting `approved`.

`isUsable()` has always checked expiry, so the source was right to answer 401 —
it refused every request. But the status it published said otherwise, so a
receiver polling it saw `approved`, believed it, and asked again with a token
that could never work. Round and round.

Both pending and approved now report `expired` once the clock has run out. The
receiver already treats `expired` as terminal, so it stops rather than
retrying.

Denied and revoked keep their own names. A decision somebody made is more use
to whoever reads it than the clock running out.

## Worth knowing

This is the same species as the `match_confidence` misreading earlier today: a
field whose value did not mean what its name implied, believed by something
downstream. The difference is that this one was believed by code rather than by
me, so it retried instead of drawing a wrong conclusion.

Worth being plain that a receiver should not need this fix to behave. It asks
permission, is refused with a 401, and the honest response to that is to stop —
which 019 now makes it do. The status being truthful is a second line, not the
only one.

## Tests

**451 PHP · 87 Vitest.** 5 new, and the suite stays green — 450 passing, 1
skipped, nothing failing.

Reverting the change turns one red. The refusals are covered too: a live
approval still reads `approved`, and a denied request that has since expired
still reads `denied` rather than losing the fact that a person said no.

The last of the five drives it end to end — the receiver polling an expired
request and marking the transfer failed — because the two halves being right
separately is not the same as them meeting.
