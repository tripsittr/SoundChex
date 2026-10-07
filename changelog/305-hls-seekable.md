# Seeking no longer re-buffers

Going back ten seconds, or forward five minutes, meant waiting.

ffmpeg writes its playlist **as it encodes**, so the playlist read moments
after a stream starts lists only the handful of segments that exist — and that
is all the player believes the film to be. Seeking past them stalled waiting
for the encoder to arrive. Seeking *backwards* re-buffered too, because every
reload handed the player a different, longer playlist to reconcile against
what it had.

## The whole timeline, up front

A VOD playlist is derivable from two numbers, both known before a frame is
encoded: the film's duration (the probe measured it) and the segment length.
So the player is given the real timeline immediately and can seek anywhere in
it.

The final segment is advertised at its true, shorter length. A player told
every segment is six seconds will seek past the end of a film whose last one
is two.

An **unmeasured** file gets no synthetic playlist — it falls back to ffmpeg's
partial one, which is what shipped before. A playlist whose duration is wrong
is worse than none, because the player trusts it and seeks into nothing.

## Segments the encoder has not reached

Promising the whole film is what makes the seek possible; producing the part
seeked to is what makes it fast. A request for a segment that does not exist
yet starts a **second** encode positioned at exactly that offset, writing into
the same directory with `-start_number` so the two interleave into one
coherent set.

Bounded at three encodes per viewing: dragging a scrubber asks for a dozen
positions in a second, and an ffmpeg for each would cost far more than the
seeks save. A claim expires after two minutes so a died encode does not block
its segment for ever.

The segment route is given only a session id, which is a hash — so what a
session is encoding is recorded when it starts, and looked up here.

## Testing

7 new tests, **372 pass**. Confirmed to fail against the bugs they guard: a
final segment advertised at full length (seeks past the end) and a missing
`ENDLIST` (the player treats it as live and refuses to seek at all).
