# 201 — Adaptive streaming for remote playback

**Merged** 2026-09-24 · **Issues** S-29

Playing from outside the house no longer means pushing a 12 Mbps remux down a
hotel connection. When the link cannot carry the file, or the client cannot
decode it, the server segments it on the fly and serves HLS instead.

## Direct play still wins wherever it can

This is **not** an HLS on/off switch. Direct play costs no CPU, loses no
quality, and seeks with a range request rather than a segment fetch — so the
setting is *when transcoding is warranted*, which is how Plex and Jellyfin
frame the same question.

Transcoding happens for two reasons only:

1. **The client cannot decode the file.** An MKV of HEVC plays in nothing;
   handing it over shows a black rectangle rather than an error.
2. **The link is too slow for it.** A 2160p file over the internet buffers;
   on the LAN it is fine. Hence the caller's address matters.

A **tailnet counts as local**. Tailscale is usually a direct encrypted path
between two machines in the same house, and transcoding a remux for a device
one room away pays CPU for nothing. `TRANSCODE_TAILNET_IS_LOCAL=false` turns
that off.

The address is read from the connection, never a forwarded header — a header
is set by whoever sent the request, so trusting it would let a remote client
claim the LAN's bandwidth.

### Settings are a ceiling and an override

- `TRANSCODE_MAX_REMOTE_HEIGHT` (default 720) caps what goes out over the
  internet. `0` disables the cap.
- `TRANSCODE_HLS_MODE` is `auto`, `never` or `always`. The last two are for
  answering "why is this transcoding?", which is the question this feature
  generates. The decision also carries its reason in the payload.

## The bug that was worth finding

`-hls_time 6` asks for six-second segments, but ffmpeg can only cut at a
keyframe — so the keyframe interval has to be forced, and `-g` counts
**frames**, not seconds.

The first version assumed 30fps. Against a 15fps source that asks for
keyframes every 12 seconds, and it produced **one 12-second segment where
there should have been two 6-second ones**. A seek would then land up to
twelve seconds from the tap. The frame rate is now probed.

## Worth knowing

- Segments are produced **while the client watches**, not ahead of time. A
  film is gigabytes and a viewer usually watches one; pre-segmenting the
  library would spend hours of CPU on things nobody opens.
- `-ss` goes **before** `-i`, where seeking is near-instant rather than
  decoding everything up to that point and discarding it.
- Finished sessions are swept hourly. Age is measured from the **newest
  segment**, not the directory's own timestamp — that does not move as
  segments are added, so a long film would otherwise be swept mid-playback.

## Still wrong

**No client uses it yet.** The endpoints answer correctly and the segmenter is
verified end to end, but neither the web player nor iOS asks
`/app/item/{id}/playback` what to do — both still go straight to the stream
route. Wiring them up is the next step, and until then this changes nothing
for a listener.

**Only one quality.** A true adaptive ladder offers several bitrates and lets
the player choose; this transcodes to one height. That is the useful 90% for a
home server, and the ladder can come later.

## Tests

PHP · 32 new across three files — the policy and its address handling (13),
the ffmpeg command shape, path guard and session sweep (12), and the endpoints
(7). Related suites 38/38.

The segmenter was verified against a real encode, which is how the frame-rate
bug was found; the suite itself stubs ffmpeg, because a test that transcodes
per case is a test nobody runs.
