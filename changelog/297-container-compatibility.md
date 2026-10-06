# Play a file the client cannot read

MKVs did not play on iOS. The cause is two things meeting:

1. **iOS cannot demux Matroska at all**, whatever codec is inside it. Handing
   an MKV to AVPlayer is a black rectangle.
2. **Nothing converted them.** `TranscodeMediaJob` writes a `converted_path`
   that `playbackPath()` already prefers, and `MediaTranscoder::needsConversion()`
   already detects an unplayable container — but the job was dispatched from
   exactly one place: a per-file button in the admin table. A library of MKVs
   stayed unplayable until somebody clicked each one.

The web player has asked `media.hls.decide` which way to play something for a
long time. The native apps had no equivalent, so they fetched the file and
hoped.

## `GET /api/v1/items/{item}/playback`

The same `StreamPolicy` the web uses, so one rule governs every client: direct
play where the file is playable and the link can carry it, HLS otherwise. It
answers with the decision, the URL to use, whether a direct download would give
this client something it can open, and whether a conversion is pending.

Asking also **queues the permanent copy** when the container is one the client
cannot read. HLS makes it play now; the converted MP4 makes every later play
direct and cheap, and makes an offline download possible at all. Idempotent via
`needsConversion()`, so repeated plays queue one job rather than one per play.

## `GET /api/v1/items/{item}/stream?variant=original`

Both files are legitimate things to want. A phone that cannot demux Matroska
needs the MP4; a desktop that can wants the original, with its full bitrate and
its other audio and subtitle tracks, because the converted copy is a
single-track H.264 reduction.

So the client can ask for either, and `direct_playable` from the playback
endpoint is what lets it offer the choice honestly — "the original will not
play on this device" rather than storing a gigabyte that shows black. A missing
original falls back to the copy rather than 404ing: a file that has gone is the
copy's whole reason for existing.

## A note on the content gate

`ContentGate` filters by **age rating, not ownership**. The pre-existing
`/stream` and `/details` endpoints both answer 200 to another account for the
same item, so this endpoint matching them is correct rather than a hole it
introduced — but whether a shared server should scope items per account is a
real question, and the same one everywhere.

The rating gate is tested both ways here: a capped profile is refused, and the
same item is allowed once the rating is within reach, so the refusal is not
passing because the endpoint refuses everything.

## Still to do

The clients do not call this yet. iOS still fetches the file directly, so the
MKV is still a black rectangle on the phone until the app is taught to ask
first — that is the next piece.
