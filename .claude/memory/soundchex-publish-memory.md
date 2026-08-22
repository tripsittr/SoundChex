---
name: soundchex-publish-memory
description: Copy new or changed memory notes into .claude/memory/ and ship them with the PR, whenever they would help another developer or agent
metadata:
  type: feedback
---

When a memory note is written or changed, **copy it into `.claude/memory/` in
the repository and include it in the PR** — whenever it would be useful to
another developer or agent rather than only to this session.

```bash
# The local directory is named after the project's full path. Derive it from
# where you are rather than hardcoding, and match it exactly — a loose glob
# like *SoundChex matches a second, unrelated project on this machine and
# would publish its notes here.
cp ~/.claude/projects/"$(pwd | tr '/' '-')"/memory/*.md .claude/memory/
```

**What is worth publishing:** how this project is built, what went wrong once
and must not again, conventions someone would otherwise guess at. Essentially
all of it — a note worth remembering across sessions is usually worth another
person reading.

**What is not:** anything about the owner's media, credentials, tailnet or LAN
addresses, or a note so specific to one afternoon that it will read as noise in
a month.

**Why:** `~/.claude/projects/…` is one machine's local directory. Notes written
to be read by whoever comes next cannot do that from there — and the copy in
the repository is a snapshot, so it goes stale silently unless it is refreshed
as part of the same change.

**How to apply:** check the published copy against the local one before opening
a PR, and correct stale notes rather than shipping them. Three were wrong on
first publish — they described a `DONE_` plan convention replaced that morning,
and would have taught the next agent something untrue.
