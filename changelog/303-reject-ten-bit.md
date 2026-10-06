# 10-bit H.264 is not direct-playable either

#305 fixed the transcode side of the 10-bit problem: `libx264` inherited the
source's pixel format and emitted **High 10**, which Apple's hardware decoder
refuses. a5's review then noted the Midnight Gospel episodes are 10-bit HEVC
Main 10 — which prompted checking the other route.

A **10-bit H.264 `.mp4`** reaches the same failure without any transcode at
all. The stream is genuinely `h264`, in a genuinely `.mp4`, so every check
passed and the file was handed over untouched — as High 10, playing black.

The probe had recorded `bit_depth` all along. Nothing consulted it.

## Verified against a real encode

```
codec_name=h264   profile=High 10   pix_fmt=yuv420p10le   bits_per_raw_sample=10
```

Before: `bit_depth=10`, `isPlayableVideo=true`. After: correctly refused, so it
routes to the transcode that now produces 8-bit High.

## Unknown depth means 8-bit

A probe that recorded no depth is treated as 8-bit, because that is
overwhelmingly what it is. Assuming otherwise would transcode every file an
older probe measured, for a case that is rare.

## Testing

3 new tests, **385 pass**. Confirmed to fail without the check, and the real
10-bit file is correctly refused.
