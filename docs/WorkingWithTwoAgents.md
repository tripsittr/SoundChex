# Working with two agents

Two machines work this repository, each with an agent on it: **`a5`** (Windows)
and **`macbookair`** (macOS). They are not interchangeable, and treating them
as if they were has already cost real work — duplicate issue ids, a second
command written before finding the one that already existed, and four
hypotheses about one bug, each paid for with a full test run.

This is the agreement that replaced that. It was set by the owner, and it is
deliberately asymmetric.

## Who does what

**Mac writes the fixes.** Every code change comes from `macbookair`.

**A5 reports.** Logs, findings, errors, reproductions, measurements — anything
observed on the Windows side goes on the issue. That is not a lesser job. The
sharpest contribution to the frozen-clock investigation was arithmetic done on
`a5`: noticing that `0.02322` at 44.1 kHz is exactly 1024 samples, one audio
buffer, which is what turned a vague stall into something specific.

**Both test before anything is called done.** Neither machine reports a feature
working on its own say-so.

## The order of operations

1. **A5 claims the issue** with a comment.
2. **Mac fixes** — commit, push, open a pull request against `main`.
3. **A5 tests and reviews the pull request fully, then approves.**
4. **Mac merges.** Not before that approval.
5. **Both run real tests.**
6. **Mac closes the issue** and tells the owner it is done.

## What "real tests" means

Explicitly **not** PHPUnit, Pest, Vitest or Playwright. Those stay in the suite
and still have to pass. They do not close an issue.

Real means driving the actual application:

- requests sent through the app rather than through a test harness
- a transfer actually run between the two machines
- a file actually played
- a page actually loaded, in a browser or on the phone

The reason is on the record. The Cancel button on the transfer page shipped
with nine passing tests behind it and did nothing at all when clicked — the
methods were callable, the markup carried the right `wire:click`, and the suite
was green. A test proves the code does what the test says. It does not prove
the feature works.

## Sign-offs

Every comment and every issue is signed:

- `— Mac` (or `— MacBook Air`) from `macbookair`
- `— A5` from the Windows machine

This matters more than tidiness. "It passes here" means something different on
each machine:

| | `a5` (Windows) | `macbookair` |
| --- | --- | --- |
| PHP + Vitest | yes | yes |
| Playwright | **no** — no `.env.e2e`, no browsers | yes |
| The real media files | ~918 of 9,737 | all of them |
| launchd / service control | no | yes |
| Building the Tauri app | untested | yes |

Anything needing Playwright, the media files, or a real device belongs on
`macbookair`. An unsigned result is ambiguous about which of those applied.

## Rules that survive from the original agreement

- **Post the evidence, not the conclusion.** Probe output — `currentTime`,
  `readyState`, `networkState`, `isBlob` — settled in one report what four
  hypotheses could not.
- **Ask rather than guess.** Each wrong guess cost the other machine a full run.
- **Ids come from `main`, not from your branch**, and are re-checked immediately
  before merging. Two `S-74`s and two `S-101`s were allocated this way.
- **Two machines, one fix.** After merging anything that changes how the
  machines talk to each other, both have to end up on it. A correction to
  `publicState()` lived on `a5` alone while the *source* of the transfer was
  `macbookair`, so the bug stayed live.
- **Correct in public.** Withdraw a claim on the issue where it was made,
  particularly one that has already gone out as advice.
