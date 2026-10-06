# Integrations that say what they are

Tracker #490.

The Integrations page kept its own list of thirteen metadata fields, while
`requiredSettings()` — the contract method that exists to drive that page — was
read **zero times**. The two drifted, and seven of those thirteen keys turned out
to be read by nothing at all: OMDb, Trakt, Discogs, Genius, Musixmatch, Google
Books and Fanart.tv. Pasting one turned the card green and changed no behaviour.

The page is now derived from what the sources declare. A source gets a card by
declaring a setting, and a key with no source behind it appears in a "Not built
yet" section that says what it *would* add — because a user who wants lyrics
should see that we know lyrics are missing rather than conclude we never thought
of it. A key already stored for one of those offers to remove it.

## What measurement turned up that the plan did not

**Spotify was configured twice, under two spellings, and worked neither way.**
The Playlist Porter plugin registered `spotify.client_id` / `spotify.client_secret`
and holds a live sign-in on them. The Integrations page and the Spotify metadata
source separately used `spotify_client_id` / `spotify_client_secret`, which
nothing else wrote. On this install the underscored secret held a value that is
**not** the one in use, and there was no underscored client id at all — so the
page reported Spotify set up while `supports()` returned false and the source
silently enriched nothing. The dotted pair wins, the source reads it, and a
migration carries the other over only where the real key is empty. Verified: the
live credentials now pass a real API test, and `supports()` returns true.

**OpenSubtitles rendered nowhere.** `groupedRows()` iterated a fixed list of
group names, which made that list a filter rather than an ordering: the card was
built in a "Subtitles" group that was not on the list and was then discarded.
Twelve of thirteen rows reached the page. Unknown groups now render after the
known ones.

**OMDb is dead too.** The plan named six unimplemented keys; grepping for actual
readers found seven.

## Also

- A **Test key** button checks a credential against its service before it is
  stored, so a bad paste never becomes a stored credential that looks fine.
  Returns the service's own wording — "Invalid API key: You must be granted a
  valid key" is more use than "Request failed (401)" — and distinguishes an
  unreachable service from a rejected key.
- Keyless sources are listed, because a page made entirely of key fields implies
  nothing works without one, when the file tagger, MusicBrainz, iTunes, Deezer
  and Open Library need nothing and do most of the identifying.
- A source needing two credentials is one card, saved both-or-neither. Half a
  pair is the state that produced the green card above.
- **OAuth foundation**: callback route, encrypted token store, and
  refresh-before-expiry, for the services a pasted key cannot authorise. Single-use
  `state`, compared with `hash_equals`. Spotify reuses the plugin's token keys and
  timestamp format rather than opening a second connection the import flow cannot
  see; Trakt is registrable but untested against the live service.

## Still broken / not done

- **Trakt has no implementation behind the sign-in.** The foundation is there and
  the card is honest about needing an app first, but nothing reads a Trakt token
  yet. The other six dead keys are unimplemented by design and labelled as such.
- The AcoustID **positive** path is proven with a faked response only — there is
  no AcoustID key on this machine to try. Its negative path was verified against
  the live API, which is how a wrong substring match was caught: AcoustID answers
  `"invalid API key"` and never mentions the client, so the check now reads its
  numeric error code.
- `php artisan test` on the whole suite OOMs in `WorkerLogTest` at the compiled
  512M default. Pre-existing on clean `main` — confirmed by stashing — and not
  caused by this change. Run per-file, or exclude that one file.

## Verified

- 1591 passed, 3 skipped, 0 failed (whole suite bar `WorkerLogTest`, which OOMs
  on this machine regardless of this branch). 39 new tests; client suite 127
  passing.
- The new tests were **mutation-checked**: the refresh margin, the
  keep-the-old-refresh-token rule, the don't-discard-on-transient-failure rule and
  all three `state` checks were each re-broken to confirm the test fails. Two
  tests passed against a re-introduced bug on the first attempt and were rewritten
  — the CSRF ones asserted only that no token was stored, which was already true
  for want of credentials, so the exchange is now faked to succeed and the state
  check is the only thing that can refuse it.
- Installed and opened against the **live library**: all five touched admin pages
  render (Integrations 94KB, Review queue 186KB), 13 of 13 integration rows
  appear, the two-field Spotify modal opens, and a credential test round-trips to
  TMDB and returns its error verbatim.

## For the reviewer (a5)

Please install, open and click through — `npm run reinstall:server`, then the
Integrations and Review pages — and say whether anything looks wrong or any log
is concerning. Two things to check that cannot be checked here:

1. The installed `Server.app` payload is a **snapshot** of the Laravel app taken
   at bundle time. The one in `/Applications` was from Sep 22 and carried neither
   this work nor the merged Phase 1–8 rebuild, so a stale install serves stale
   code. Worth confirming your install is current before judging behaviour.
2. `server:health` on this machine reports **8 failed jobs** and **8 hidden items
   with nothing saying why** — six are pre-existing `needs_review` rows whose
   review item was closed without clearing the status, and two are test rows my
   own verification leaked into the dev database. The leaked rows need removing;
   the six look like a real gap in `ReviewLog`, worth a look on a library whose
   media is actually present.
