# 003 — Moving a server to another machine, and preparing for Windows

**Merged** pending · **Issues** S-52, S-54, S-55

Copy a whole SoundChex to another machine over a tailnet or forwarded address —
database, media and profiles — and the groundwork for that machine being a
Windows one.

## What changed

### A server can be copied to another machine

The receiving server asks; the request sits **pending** until a person on the
source approves it. Nothing about the library is disclosed before that — not
its size, not its name, not whether it holds anything at all.

Approval is the security boundary rather than a token, and deliberately so: a
token copied between machines can be copied a third time, and once issued it
exists whether anyone is watching or not. A request that must be approved while
someone is looking at it cannot be used by someone who is not.

Both screens show a short code. Matching it is how you know the request in
front of you is the one just started, rather than another arriving at the same
moment.

**Every file is verified twice.** Before, so one already present with the right
hash is skipped — which makes a resumed transfer cheap and a repeated one free.
After, because a truncated film that looks like a film is worse than no film:
nothing will ever tell you it is wrong. A mismatch deletes the partial file and
records why.

**Resuming and starting are the same operation.** `transfer_items` is both the
work list and the bookmark, so an interrupted transfer asks exactly the question
it asked at the beginning. On this library that is 8,315 files and 46.3 GB —
hours, and hours is long enough to be interrupted.

**Approval is revocable while it runs.** Every request the receiver makes
re-checks the state, so stopping actually stops it.

### The catalogue actually arrives

The source served the database and nothing on the receiving end asked for it,
so "the catalogue" was a checkbox that did nothing. It now downloads, unpacks
and replaces — after backing up the existing one, without asking, because
someone who chose it has almost certainly not thought about losing their own
play history.

What arrives is checked for a SQLite header before anything is replaced. The
realistic failure is a token that expired: the request comes back 200 with a
login page, and replacing a catalogue with HTML would be discovered only
afterwards.

### What travels is a choice

The catalogue (minutes), the media (hours), profiles and history (the part
rescanning cannot rebuild), and settings — which usually should not travel,
because the new machine wants its own.

### Windows, prepared but unproven

Three things would have broken, one of them completely. **Absolute paths were
detected by a leading separator**, which is the whole test on Unix and misses
every Windows path — `C:\Users\...` would have read as relative and made *every
file in the library* unreadable at once. LAN address detection ran a macOS-only
command, and Tailscale was looked for only in Homebrew paths.

`docs/SettingUpOnWindows.md` is a step-by-step with a check after each step,
because several of the failures are silent.

### The planning folder is a third the size

28 markdown files down to 12. Sixteen deleted, each recorded in `Issues.md`
first with what was built and what verified it. Checked against the code rather
than their own labels — one said "Not started" and had shipped that morning.

## Worth knowing

- **Two migrations**: the three transfer tables, and a column holding the token
  on the machine that issued it.
- **Both machines need `queue:work` running**, or a transfer is approved and
  then sits doing nothing. On Windows that is a terminal of its own — the
  Services page is launchd-only.
- The stored `content_hash` is **xxh128, not sha256**. Verified against a real
  file; getting it wrong would have marked every file corrupt, deleted it, and
  reported a transfer where nothing arrived.
- Media is not compressed and the database is. Measured: an MP3 gzips by 0.4%,
  the database by 85%.

### A test destroyed the development database

Writing the catalogue import, `database_path('database.sqlite')` looked like
the obvious way to name the file. It is a hardcoded path that ignores the
connection entirely — so under test, where the connection is `:memory:`, it
wrote to the real library's database and replaced it with the gzipped HTML the
test was feeding it.

Restored from `storage/backups/`. Both the receiver and the source now read the
path from the connection, and refuse when there is no file — which is every
test run.

The lesson is not "be careful with tests". It is that a hardcoded path is not a
detail when the thing at the end of it is the only copy.

## Still wrong

- **Nothing has been transferred between two real machines yet.** Every stage
  is verified against a running server — request, approval, token, manifest,
  revocation — but the two ends have not met. `a5.tail7e590c.ts.net` is the
  intended test, and is a Windows machine, so it is the first real test of both.
- **No Windows build has been completed.** The instructions come from Tauri's
  requirements and from reading this codebase for its Unix assumptions, not from
  a build anyone has watched succeed. The three path fixes above are unrun there.
- **Choosing "profiles" without "the catalogue" does nothing** (S-73). The
  database carries profiles with it, so they arrive with the catalogue —
  importing them separately would be a merge into an existing catalogue, which
  is a different problem.
- Pages still have no back arrow (S-52).

## Tests

304 PHP · 87 Vitest · 283 of 287 Playwright. 15 new tests cover approval,
denial, expiry, revocation, scope, verification and resume.

The four failures are the S-04 pattern — mobile specs that pass alone and fail
in the full run — with one exception that was a real bug in a test I wrote:
`download-logging` asserted across *every* `:failed` event rather than the one
it meant, so an unrelated `sync:failed` satisfied "something failed" and then
failed the url check. It failed on mobile, where the sync does fail, and passed
on desktop, where it does not. Narrowed to the specific event.

One was found toothless and fixed: the corrupt-file test sent a response
*shorter* than expected, so the size check caught it and the hash check never
ran. Removing the hash guard entirely still passed. It now sends bytes of the
same length.
