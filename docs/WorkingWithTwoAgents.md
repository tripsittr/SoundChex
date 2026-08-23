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

## Neither machine can measure the other's progress

Ask, do not infer. Both of us got this wrong on the same transfer, in opposite
directions.

They are two different failures, and the difference is the useful part.

**A sampling error.** Mac read the tailnet byte counters and called a running
copy stalled — twice. The transfer moves in bursts, so a thirty-second sample
lands in a gap between files and reads as zero. The totals across the same
period were 461 → 496 → 537 → 553 MB: a copy in progress the entire time. A
real instrument measuring a real thing over too short a window. The fix is to
sample for longer.

**A number nobody measured.** A5 reported `failed: 0` on a queue it had reset
by hand minutes earlier — a number it had caused rather than observed, and
flagged as such in the same breath. No sampling window would have helped,
because nothing was being sampled.

The second is the more dangerous. A bursty counter makes you doubt something
that is working; a number you produced yourself makes you believe something
that has not happened. Mac was about to read that `0` as a fix confirmed on
real data, and would have, had it not arrived already labelled.

A number from your own side describes your own side. A number you *changed*
yourself is not a measurement at all. Progress belongs to the machine doing the
work: ask on the issue, and if you must sample, sample across minutes rather
than seconds and say what the instrument was.

**Reviewing perturbs the thing being measured.** Checking out a branch to
review it swaps the code under a live `queue:work`, which stops the run — and
from the other machine that is indistinguishable from a crash. It happened
once and was reported as a stalled transfer before anyone connected the two.

**So review in a worktree while anything is running:**

```bash
git worktree add ../review-<branch> <branch>
# run the suite there, then
git worktree remove ../review-<branch>
```

The checkout is a separate directory, the worker keeps running against the
code it started with, and nothing has to be announced or timed. Proven on a
live copy: 219 files landed *during* a review that would previously have
stopped it dead.

Better than the warning it replaces. A practice that cannot go wrong beats
one that depends on both sides remembering to say so.

## What an A5 approval can and cannot attest to

`a5` has no Playwright — no `.env.e2e`, no browsers installed. So an approval
from there is not a statement that a change works in a browser, and must never
be read as one.

What `a5` can be held to:

- **PHP and Vitest**, full suites — including breaking a guard on purpose to
  check the test can actually fail.
- **Real requests against the running app.** It serves on `0.0.0.0:8000`, so
  artisan commands, API calls and HTTP round trips are all testable there.
- **Reading the diff against the code it touches.** That is where a duplicate
  `library:duplicates` command was caught before it was written twice.

**An approval says which of those were done, and names any browser-shaped risk
it could not cover.** Where such risk exists, Mac tests it here before merging —
`a5`'s approval does not substitute for that.

The reverse also holds. `a5` is the **receiving** machine in a transfer, so
transfer behaviour cannot be tested from this side alone. Six of seven transfer
bugs today were only visible there.

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
