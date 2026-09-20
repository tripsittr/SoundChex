# 125 — A Sessions page: who is signed in and what they're playing (S-36)

Device tracking answered "what went wrong on a device" (device reports). It did
not answer "who is using the server right now" — the login and listening side of
S-36. This adds that.

## What this adds

A **Sessions** page under System, read-only apart from one action:

- **Signed-in devices** — one row per Sanctum access token: the device name it
  was created with, the profile baked into its ability (`profile:N`), when it
  signed in, and when it was last active. A dot marks a device that acted in the
  last 15 minutes. **Sign out** revokes that token, so a lost or old device can
  be cut off from here.
- **Listening** — the most recent play per profile in the last day: the profile,
  the item, the resume position (formatted `m:ss` / `h:mm:ss`), where the play
  started from, and when. A dot marks one that is playing now.

## Notes

- Nothing new is stored: the login side reads `personal_access_tokens`
  (`last_used_at`, the `profile:N` ability), the listening side reads the
  existing `media_plays` rows. It is a reporting view over data already kept.
- Added a `profile()` relation to `MediaPlay` (the column existed; the relation
  did not).
- Expired tokens are excluded; the newest play per profile is shown so the list
  is one row per person, not one per position update.

## Tests

- `SessionsPageTest` — lists a signed-in device with its profile; sign-out
  revokes the token; shows what a profile is listening to with the position
  formatted; only the latest play per profile appears.
- Full suite: 732 passed.
