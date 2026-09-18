# 095 — Bundled server Step 8: bundle full GPL ffmpeg (S-151)

*2026-09-18.*

## Done

Under AGPLv3, GPL ffmpeg is compatible, so the runtime now bundles a **full
static GPL ffmpeg** — transcoding works out of the box.

- **`package-runtime.sh`** fetches a full static GPL ffmpeg + ffprobe per
  platform (`--enable-gpl`, libx264, libmp3lame): macOS from
  ffmpeg.martin-riedl.de (native per-arch), Linux/Windows from BtbN/FFmpeg-Builds.
  Asserts the GPL/x264/mp3lame config on POSIX. `SKIP_FFMPEG=1` opts out.
- **`config/transcode.php`** auto-detects a bundled ffmpeg **beside the PHP
  binary** (`dirname(PHP_BINARY)/ffmpeg`, the cacert convention), so the bundled
  runtime transcodes with zero config. Explicit `FFMPEG_PATH`/`FFPROBE_PATH` still
  wins; else PATH.
- **THIRD-PARTY-LICENSES** now carries the ffmpeg GPL notice (+ libx264 GPL,
  libmp3lame LGPL, and the H.264-patent note).

## Verified

Built a macOS bundle with ffmpeg (63 MB each): the bundled ffmpeg does a real
**libx264 H.264 encode** to a valid MP4 (ffprobe reads `h264`) and an **mp3
encode** via libmp3lame. The app config resolves to the bundled binary under the
bundled php; env-override and PATH-fallback covered by a new test. 2 existing
transcode tests still pass.

## Note

ffmpeg adds ~120 MB to a bundle — acceptable for a media server; `SKIP_FFMPEG=1`
builds a lean runtime for hosts that already have ffmpeg.
