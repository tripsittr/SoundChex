# 006 — Publishing what each change teaches

**Merged** pending

`.claude/memory/` is a copy of a local directory, so it goes stale the moment
anything is learned and not copied across. Now part of the PR routine.

## What changed

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

## Tests

**312 PHP · 87 Vitest.** Playwright not run — documentation only.
