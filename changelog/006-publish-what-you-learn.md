# 006 — The catalogue download, and publishing what each change teaches

**Merged** pending

`.claude/memory/` is a copy of a local directory, so it goes stale the moment
anything is learned and not copied across. Now part of the PR routine.

## What changed

### The catalogue download was returning a 500

`gzopen('php://output')` fails with `could not make seekable` — the handle
seeks and output does not. So the first real transfer between two machines got
an error where the catalogue should have been, after the request, the approval
and the token had all worked.

Compressed to a file and sent with `readfile()` instead. Costs a few seconds
and a few megabytes against a database that shrinks 24 MB to 3.6.

Found in the logs rather than by a test: every test asserted the endpoint
answered, and it did — with a 500.

Rule 6 in `AGENTS.md`: if a change taught you something another developer or
agent would want — a convention, a trap, something that went wrong once — write
it into `.claude/memory/` and ship it in the same PR.

## Worth knowing

The obvious copy command is wrong:

```bash
cp ~/.claude/projects/*SoundChex/memory/*.md .claude/memory/   # don't
```

There are **two** SoundChex projects on this machine, and that glob matches
both — it would publish an unrelated project's notes into this repository. The
directory is named after the project's full path, so derive it exactly:

```bash
cp ~/.claude/projects/"$(pwd | tr '/' '-')"/memory/*.md .claude/memory/
```

### A preference that was written nowhere durable

"Nothing published mentions AI or carries a co-author trailer" had been in
effect all session and existed only in a memory directory belonging to a
project path that no longer exists — found while checking why a glob matched
two SoundChex projects when only one is on disk.

It is now in `.claude/memory/` with the rest, which is the point of publishing
them.

## Tests

**313 PHP · 87 Vitest.** Playwright not run — no browser code changed.
