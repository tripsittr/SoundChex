# Moving a server to another machine

The database, the media and the profiles, from one SoundChex to another over a
tailnet or a forwarded address — resumable, verified, and honest about what
failed.

## The shape of the problem

Measured on this library:

| | |
| --- | --- |
| Files | **8,315** |
| Media | **46.3 GB** |
| Database | 24 MB (3.6 MB gzipped) |
| Profiles | 1 |
| Already content-hashed | 6,977 of 8,315 |

46 GB over a home connection is hours, and hours is long enough that the
transfer *will* be interrupted — a laptop sleeping, a network dropping, a
machine rebooting. Resuming rather than restarting is the feature, not a
refinement of it.

## What already exists, and what does not

`library:export` and `library:import` move metadata as CSV. No files, no
transfer, no resume. They are not a foundation for this.

What is worth reusing:

- **`content_hash`** on `media_items` — the verification primitive, already
  populated for 84% of the library.
- **API token auth** (`POST /api/v1/tokens`, `auth:sanctum`) — the receiving
  server has to prove who it is, and this already works.
- **The queue** — `queue:work` runs on both machines already.
- **`NetworkAddresses`** — how a server describes where it can be reached.

## Which way the data flows

**The receiving server pulls.** It asks the source for a manifest, then fetches
files one at a time.

Pull rather than push, for three reasons. The receiver knows what it already
has, so it decides what to ask for — which is what makes a resume cheap. It
controls its own disk and can stop when it is full. And it needs the code
anyway, so there is no asymmetry to justify.

Both machines run SoundChex.

### Approval, not a pre-shared token

The receiver does not arrive holding a credential. It **asks**, and the request
sits pending on the source until a person there approves it.

```
receiver                          source
   |  POST /transfer/requests        |
   |-------------------------------->|  request stored, state: pending
   |  { pending, id, code: 4821 }    |  admin page shows it, with the code
   |<--------------------------------|
   |                                 |
   |  (a person approves it)         |
   |                                 |
   |  GET  /transfer/requests/{id}   |
   |-------------------------------->|
   |  { approved, token }            |
   |<--------------------------------|
   |                                 |
   |  everything else, with token    |
   |-------------------------------->|
```

This is better than a token generated in advance for a specific reason: a token
copied between machines can be copied twice, and once issued it exists whether
anyone is watching or not. A request that must be approved while someone is
looking at it cannot be used by someone who is not.

**What the source shows before anyone approves:** the address it came from, the
device name and platform the requester reports, what is being asked for
(metadata, media, profiles), how much that is, and a short code the requester
also displays. Matching the code is what tells you the request on your screen is
the one you just started, rather than someone else's arriving at the same
moment.

**Pending requests expire.** An unapproved request is worthless after a few
hours and becomes a way in if left forever; they lapse rather than waiting
indefinitely.

**Approval is revocable.** A transfer that has been approved and is running can
be stopped from the source, and the token dies with it. A 46 GB transfer takes
hours, which is long enough to change your mind.

**The token is scoped and single-purpose.** It reads the manifest and the files
and nothing else, it belongs to that one request, and it is useless once the
request is complete or revoked.

## Compression

Measured rather than assumed:

| | |
| --- | --- |
| An MP3, gzipped | 4,905,709 → 4,887,581 bytes — **0.4%** |
| The database, gzipped | 24 MB → 3.6 MB — **85%** |

So: **compress the database, never the media.** MP3, MP4, FLAC, EPUB and CBZ
are already compressed containers; gzipping them spends CPU on both machines to
save nothing, and on a slow connection the CPU is not the bottleneck anyway.

**Batching is a different question from compression, and the answer is also
no.** Tarring a hundred files into one request would mean a failure part way
loses the whole batch, and the per-file record — which is the point of this —
would have nothing to attach to. HTTP keep-alive already avoids a new
connection per file. One file per request, resumable by byte range.

## Plan

### 1. A transfer as a record (~1 day)

`transfers` and `transfer_items`. A transfer holds where it is going, what was
chosen, when it started and what state it is in. An item holds one file, its
expected hash and size, its state, and the reason if it failed.

The bookmark is not a separate concept: it is the item table. Anything not yet
`complete` is what is left to do, so an interrupted transfer resumes by asking
the same question it asked at the start.

### 2. Requests and approval on the source (~1 day)

`transfer_requests`: where it came from, what the requester says it is, what it
wants, a short code, a state, and when it expires.

Two endpoints that need **no** authentication, because a request is how
authentication is obtained:

- `POST /api/v1/transfer/requests` — heavily rate-limited, since it is the one
  unauthenticated write in the system. Stores the request, returns its id and
  the code.
- `GET /api/v1/transfer/requests/{id}` — the receiver polls this. Returns
  `pending`, or `approved` with the token, or `denied`, or `expired`.

An admin page listing pending requests with **Approve** and **Deny**, showing
the address, the reported device, what is being asked for, how large it is, and
the code to check against the other screen.

Approving mints a scoped Sanctum token tied to that request. Denying, revoking
or expiring kills it.

### 3. Serving the data (~1 day)

Three endpoints, all requiring a token from an approved request:

- `GET /api/v1/transfer/manifest` — what is here: every item's id, hash, size
  and relative path, plus the profiles and the database's own hash. Paginated,
  because 8,315 rows is not one response.
- `GET /api/v1/transfer/file/{item}` — one file, honouring `Range` so a partial
  download resumes at the byte rather than the file.
- `GET /api/v1/transfer/database` — a gzipped dump, taken at a moment rather
  than streamed live.

Read-only. A transfer never writes to the source, so a mistake at the receiving
end cannot damage the machine being copied.

### 4. The receiving side (~2 days)

An admin page that takes an address and a token, fetches the manifest, and
shows what it would do before doing it: how many files, how many gigabytes, how
many are already present.

Then a queued job per file:

- **Before:** does a file already exist at the destination with this hash? Skip
  it. This is what makes a resumed transfer cheap and a repeated one free.
- **Transfer:** stream to a temporary path, with `Range` if resuming.
- **After:** hash what arrived. If it does not match, delete it and record the
  mismatch — a truncated file that looks present is worse than one that is
  plainly absent.
- **Then:** move it into place and mark the item complete.

Failures are recorded per item with the reason and the attempt count, retried a
few times, and left visible rather than retried forever.

### 5. What travels (~half a day)

Four choices, because they have genuinely different costs:

- **Metadata** — the catalogue. Minutes. Useful alone: a second machine that
  can browse the library and stream from the first.
- **Media files** — the 46 GB. Hours.
- **Profiles** — people, history, resume points, watchlists, ratings. Small,
  and the part that cannot be rebuilt by rescanning.
- **Settings** — API keys, watch folders, library preferences. Optional
  because a second machine often wants its own.

Metadata and profiles arrive as a database import rather than row-by-row: it is
one file, it compresses 85%, and it is atomic.

### 6. Password-gated on both ends (~half a day)

Both pages ask for the account password: the receiver before sending a request,
the source before approving one. A second deliberate act rather than a click,
and on the source it is the act that matters most — approving is what hands
over the library.

The password is not the security boundary, though. That is the approval itself:
nothing can be read from the source until a person there says so, and what they
say yes to is revocable while it runs.

### 7. Tests (~1.5 days)

- A resumed transfer skips what is already present and hashed.
- A truncated file is detected, deleted and recorded — never left in place.
- A failure records its reason and does not stop the rest.
- A transfer interrupted mid-run resumes from the item table.
- The source endpoints refuse an unauthenticated request.
- A request sits pending until approved, and the manifest is refused until then.
- A denied request never yields a token.
- An expired request cannot be approved afterwards.
- Revoking a running transfer stops it, and the token stops working.
- A token from one request cannot be used for another.
- Choosing metadata only moves no files.
- A hash mismatch on the database aborts before importing.

**Total: ~7.5 days.**

## Deliberately not doing

- **Two-way sync.** This is a move or a copy, not a merge. Deciding which side
  wins when both changed is a much larger problem and is not the one asked
  about.
- **Compressing media.** Measured at 0.4%.
- **Batched archives.** A failure part way loses the batch, and per-file
  tracking is the requirement.
- **Deleting from the source.** A transfer that removes what it just copied
  offers no way back if the copy turns out wrong. Emptying the old machine is a
  separate, deliberate act.

## Risks

**The database is the whole catalogue.** Importing one over another replaces
play history, playlists and profiles. The receiving server backs up its own
first, automatically, before any import.

**46 GB is long enough for the source to change.** A file transferred at hour
one may be re-tagged by hour four, and the manifest will not know. The hash is
checked at the start of each file, so a changed file is re-fetched rather than
silently stale.

**Disk space.** The receiver checks free space against the manifest total
before starting, and stops cleanly when it runs out rather than filling the
disk.

## Not started
