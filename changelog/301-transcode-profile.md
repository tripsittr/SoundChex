# Audio played, the picture stayed black

Reported twice, and both times it read as a broken stream. It was an
unsupported **H.264 profile**.

`libx264` inherits the source's pixel format unless told otherwise. A 10-bit
rip — common for anime and for anything encoded from a good master — therefore
came out of the HLS transcode as **High 10**, which Apple's hardware decoder
refuses outright. The audio track decodes fine, so the player runs the clock
over a black rectangle.

## Measured, not reasoned

Running the server's exact command over a `yuv420p10le` source:

```
before:  profile=High 10   pix_fmt=yuv420p10le
after:   profile=High      pix_fmt=yuv420p
```

`-profile:v high -level:v 4.1 -pix_fmt yuv420p`. Level 4.1 is the ceiling every
iOS device since the 4S decodes, and it covers 1080p comfortably.

## The lesson already existed

`MediaTranscoder` — which writes the permanent converted copies — has set
`-pix_fmt yuv420p` all along, with a comment reading *"10-bit sources otherwise
produce a file nothing will play"*. The HLS path never learned it.

So the profile is now pinned in **both**, and asserted rather than left to a
comment.

## Testing

Two tests. One checks the argument list; the other **runs ffmpeg for real**,
encodes a genuinely 10-bit source through the server's own command, and probes
the resulting segment for `High 10`. The second is the one that matters: an
argument assertion alone would have passed while the output was still
unplayable.

Both confirmed to fail against the shipped code. **325 pass** across the
streaming and media suites.
