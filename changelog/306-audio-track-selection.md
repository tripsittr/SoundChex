# Choosing which audio track to hear

A rip routinely carries the original language, a dub or two and a commentary.
The probe has recorded all of them since it was written — codec, channels,
**language**, the default flag — and **none of it was ever sent to a client**.
So every player played whichever track happened to be first, and offered no
way to change it.

The transcode made that permanent. `-map 0:a:0` was hardcoded, so even a
client that *could* choose had nothing to choose between: the stream contained
exactly one track.

## What changed

- **`audio_tracks` on the detail endpoint** — index, label, language, channels,
  codec and the default flag.
- **`?audio=N` on the HLS playlist**, threaded through to `-map 0:a:N`.
- **The track is part of the session id**, so switching language produces a
  different stream rather than reusing the one already encoded with the old
  audio.

## Labels

"English 5.1" and "English Stereo" in the same menu is the other reason this
exists — picking the surround mix over the downmix. So the label is language
plus layout, not language alone.

A track with no language falls back to "Track 2", because an empty row is not
a choice and that is.

## Clamping rather than refusing

A request for track 9 of a two-track file maps nothing and produces a
**silent** stream — which reads as a broken encode rather than a bad
parameter. It is clamped to what the file has.

## Verified against a real file

Built a two-track MKV (English at 300Hz, Japanese at 900Hz) and ran the
server's own command for each: track 0 produced the 300Hz tone, track 1 the
900Hz one. The selection genuinely changes the audio, not just the arguments.

## Testing

9 new tests, **394 pass**. Three mutations confirmed to fail: the hardcoded
`0:a:0`, the track left out of the session id, and the tracks never reaching
the client.

## Next

The iOS side — a track menu in the player — is the follow-up, and is the first
piece of the custom player work (#517).
