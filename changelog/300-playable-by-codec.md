# A container is not a codec

`isPlayableVideo()` asked only the **file extension**. So a `.mp4` carrying
HEVC video, or one whose only audio track is AC-3, passed as playable, was sent
to direct play, and died on the device — while looking perfectly ordinary on
the server.

An `.mp4` of HEVC is as unplayable as an MKV. It just fails later and less
obviously, because the container opens fine.

## Audio was never checked at all

Nothing anywhere looked at audio codecs. An AC-3 or DTS track played
**silently** — a file that appears to work and does not, which is worse than
one that plainly fails.

## What it does now

Both questions, because they are genuinely separate:

- **The container** decides whether the player can open the file. iOS cannot
  demux Matroska whatever is inside it, so an MKV of plain H.264 is still
  unplayable there.
- **The codecs** decide whether it can decode what it finds, read from the
  probe that already measured them.

Any one playable audio track is enough: the transcoder maps a single stream and
a player picks one it can decode, so a rip carrying AC-3 *and* AAC plays
through the AAC rather than burning CPU on a needless transcode.

## Deliberate limits

**An unprobed file is still judged on its container alone.** "Unknown" must not
silently become "unplayable" — that would transcode the entire library until
the probe caught up. (`library:probe` now runs hourly, so this shrinks on its
own.)

**The probe is only consulted when the relation is loaded**, because this is
called once per row on listings and a lazy read would be a query each. The
endpoints that decide playback load it explicitly; a listing falls back to the
container, which is the cheap and safe answer.

## Testing

11 new tests, **372 pass** across the playback, streaming, media and probe
suites. Both halves confirmed to fail when removed: judging on the extension
alone (4 tests fail), and ignoring audio codecs (2 fail).

Worth noting the container check was added *because* a test caught its
absence — H.264-in-MKV reported playable when only codecs were checked.
