# Transcoded video played black on iOS

An episode would start, the clock would run and the duration was right — and
the picture stayed black. Reported on *The Midnight Gospel*, where every
episode but the first transcodes.

## Why

`AVURLAssetHTTPHeaderFieldsKey` applies to the **playlist** request only.
AVFoundation does not propagate those headers to the individual `.ts` segment
fetches, so a segment arrived with no credentials at all. The segment route sat
behind session auth, which answered **302 → /login**; AVPlayer followed the
redirect and decoded the login page as video.

The duration and the clock worked because the *playlist* had carried the header
and loaded fine. That is what made it look like a video problem rather than an
auth one.

Measured directly: a segment request with no `Authorization` header returns
`302 → https://…/login`.

## The fix

A token client now gets **signed** segment URLs. The signature travels in the
URL, which is the one credential a player reliably keeps, and it is the right
shape for the job: unguessable, scoped to one session and file, and expiring
after twelve hours.

The session id cannot do that job, which is worth writing down because the
shortcut is tempting: it is a deterministic `sha256` of
`(item, updated_at, height, start)`, so anyone who knows the item can compute
it. There is a test asserting it is stable, precisely so nobody later mistakes
it for a secret.

A browser is unaffected — it carries its cookie on every segment, so the web
routes keep plain URLs and signing would be noise.

## Also

`/api/v1/items/{item}/playback` now points at the **API** playlist rather than
the web one. Handing a token client a session-authed URL was the other half of
the same mistake.

## Testing

8 new tests, **290 pass** across the streaming and API suites. Each was
confirmed to fail against the bug it guards:

| Mutation | Caught by |
|---|---|
| playback points back at the web playlist | `an_unplayable_file_is_sent_to_the_api_playlist` |
| `signed` middleware removed | the unsigned, tampered and expired tests (3) |
| segments not signed in the playlist | `an_api_playlist_names_signed_segments` |

The unsigned case asserts specifically that the refusal is **not a 302**,
because a redirect is what broke this: AVPlayer follows it and decodes HTML as
video.
