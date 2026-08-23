# 025 — A manifest a file transfer can read

**Merged** 2026-08-23 · **Issues** S-105, GitHub #34

`wants: ['files']` could not work. Not through misuse — by construction.

## What changed

### The manifest is reachable by a transfer that only wants files

`manifest()` was gated on the `metadata` ability. But the manifest *is* the
list of files: which ones exist, where they go, how big they are, what they
hash to.

So a transfer approved for `files` could authenticate, ask what to fetch, and
be refused `403 This transfer did not ask for that.` It had permission to
download files and no permission to learn which files existed.

Nothing rejected the combination when the request was made — `RequestController`
validates each want against `in:metadata,files,profiles,settings` individually,
so `['files']` passes validation and then cannot do anything.

Found by a real request: transfer #10 on `a5` took its token, asked for the
manifest and was refused, correctly, by a rule that made its own request
impossible to fulfil.

`manifest()` now accepts either ability.

### The catalogue dump is not widened with it

`database()` stays on `metadata` alone. Widening the file list must not widen
the database that describes the whole library, and there is a test that fails
if it ever does.

## Worth knowing

- No migration, no schema change.
- `authorizeTransfer()` now takes a string or an array; any one of the listed
  abilities is enough.
- Both tests were checked by reverting the fix, which turns the first red.
- **Related but not fixed here**: S-73, `profiles` without `metadata`. That one
  is a receiver-side problem — `profiles()` serves its own data and works
  standalone; it is the import that ignores it.
