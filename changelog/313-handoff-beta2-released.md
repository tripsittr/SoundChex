# 313 — Handoff: server-v0.2.0-beta.2 is released

**Merged** — · **Issues** #521–#526

Docs only. The handoff said the HatCOS release was not tagged. It now says
where things stand.

## What changed

### Handoff.md

- #311 and #312 were merged on 6 November at the owner's direction, without
  a5's approval. a5's review is still owed.
- The pre-release `server-v0.2.0-beta.2` is published, and the handoff gives
  the fixed URL HatCOS's package is to download.
- The aarch64 .deb was downloaded by that URL on the Mac and matched its
  `.sha256`. That is the only thing confirmed: HatCOS has not fetched it yet.
- a5 now runs HatCOS (x86_64), not the Windows install AGENTS.md §0 describes.

### BundledServer.md and the 311/312 changelogs

The plan no longer says the Linux CI builds are unverified, since they produced
the released packages. Both changelogs now have their merge date.

## Worth knowing

AGENTS.md §0 still describes a5 as Windows, which this PR leaves unchanged.
That is the working agreement between the two machines, and changing it is the
owner's call.

## Still wrong

Nothing here has run on Linux. That part of the handoff is unchanged.

## Tests

None: no code changed. A subagent checked every claim against GitHub: merge
state and dates, the release, where its tag points, asset names and the two
`.sha256` files. The one it could not check, the .deb download, is now worded
as what was run, where, and when.
