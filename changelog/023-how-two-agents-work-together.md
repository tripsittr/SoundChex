# 023 — How two agents work together

**Merged** 2026-08-23 · **Issues** S-103

Two machines work this repository, each with an agent on it. Until now they
worked the same way as each other, which produced duplicate issue ids, a second
command written before finding the one that already existed, and four
hypotheses about a single bug that each cost a full test run to disprove.

This writes down the arrangement that replaced that. It is documentation only —
no code changes.

## What changed

### The work is now asymmetric on purpose

**Mac writes every fix. `a5` writes none.** `a5` reports logs, findings, errors,
reproductions and measurements on the issue, and that is what the fixes are
built from.

That is not a lesser role. The sharpest contribution to the frozen-clock
investigation came from `a5`: noticing that `0.02322` at 44.1 kHz is exactly
1024 samples — one audio buffer — which turned a vague stall into something
specific enough to test.

### Nothing merges on one machine's approval

`a5` claims the issue → Mac fixes, commits, pushes, opens a PR → **`a5` tests
and reviews it fully and approves** → Mac merges → both run real tests → Mac
closes the issue.

### "Real tests" excludes the test suites

PHPUnit, Pest, Vitest and Playwright still have to pass. They no longer close
anything.

Closing an issue means driving the actual application: requests through the app,
a transfer actually run between the machines, a file actually played, a page
actually loaded on a device.

The precedent is on the record. The Cancel button on the transfer page shipped
with nine passing tests behind it and did nothing when clicked — the methods
were callable, the markup carried the right `wire:click`, and the suite was
green.

### Everything gets signed

`— Mac` from `macbookair`, `— A5` from the Windows machine, on every comment and
issue.

The machines cannot do the same things — `a5` has no Playwright, no `.env.e2e`
and about 918 of 9,737 media files — so "it passes here" means something
different depending on where "here" is. An unsigned result hides that.

## Worth knowing

- No migrations, no schema changes, no code changes.
- `AGENTS.md` gains this as rule 0, above "never commit media", because it
  governs how every other rule gets applied.
- The full agreement is in `docs/WorkingWithTwoAgents.md`.
- GitHub #31 carries the discussion; S-103 carries the record.
