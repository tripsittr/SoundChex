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

Both machines run SoundChex. The source needs no configuration beyond issuing a
token.

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

### 2. The source side (~1 day)

Three endpoints, all `auth:sanctum`:

- `GET /api/v1/transfer/manifest` — what is here: every item's id, hash, size
  and relative path, plus the profiles and the database's own hash. Paginated,
  because 8,315 rows is not one response.
- `GET /api/v1/transfer/file/{item}` — one file, honouring `Range` so a partial
  download resumes at the byte rather than the file.
- `GET /api/v1/transfer/database` — a gzipped dump, taken at a moment rather
  than streamed live.

Read-only. A transfer never writes to the source, so a mistake at the receiving
end cannot damage the machine being copied.

### 3. The receiving side (~2 days)

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

### 4. What travels (~half a day)

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

### 5. Password-gated, and why that is not enough (~half a day)

The admin page asks for the account password before starting — a second
deliberate act, not a click.

But the real protection is the token. A transfer is authenticated with a
Sanctum token issued by the *source* server, scoped to transfer only, and
revocable. A password on the receiving side stops someone using an unlocked
laptop; it does nothing about who may read the source. Both are needed and they
do different jobs.

### 6. Tests (~1.5 days)

- A resumed transfer skips what is already present and hashed.
- A truncated file is detected, deleted and recorded — never left in place.
- A failure records its reason and does not stop the rest.
- A transfer interrupted mid-run resumes from the item table.
- The source endpoints refuse an unauthenticated request.
- Choosing metadata only moves no files.
- A hash mismatch on the database aborts before importing.

**Total: ~6.5 days.**

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
