# Changelog

One file per pull request merged to `main`, named `NNN-short-name.md` after the
PR number.

## Why per-PR rather than one file

A single `CHANGELOG.md` becomes a merge conflict on every branch and a wall of
text nobody reads. One file per PR conflicts with nothing, and reading the
folder in order tells you what happened and when.

## What goes in one

Written for whoever reads it in six months, which is usually us. That means:

- **What changed, from the outside.** "Titles no longer show the artist twice",
  not "modified FileTagger::writeTitle".
- **Why, when the reason is not obvious.** A fix that looks strange usually
  prevented something specific; say which.
- **What it touched that someone should know about** — migrations, data
  rewritten, anything that needs a step on another machine.
- **What is still wrong.** A release note that only lists wins is a release
  note people stop trusting.

Skip the commit list. `git log` already has it, and better.

## Template

Copy `TEMPLATE.md`.

## Writing one

At the point the PR is opened, not after it merges — the reasoning is still to
hand, and the PR body and the changelog want to say the same thing anyway.
