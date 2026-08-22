---
name: soundchex-audit-tests-before-done
description: "Before marking a SoundChex issue Done, audit the tests for toothless tests, redundant tests, and coverage gaps"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: b8c115fe-eb5d-4851-b41f-6a5259bf8b30
  modified: 2026-08-13T20:54:14.063Z
---

Before moving an issue to Done, **audit the test suite it added**
for three specific failures. Passing tests are not evidence on their own.

**Why:** a test that cannot fail is worse than no test — it reports safety that
does not exist, and the gap it hides is invisible precisely because something
green is sitting on top of it. This surfaced repeatedly while building the
SoundChex suite: an access test asserted `#np-title` against a property name
(`.audio`) that did not exist on the player, so it would have read "not
playing" no matter what the code did; another asserted playback survived a
click on `/app/downloads`, one of the four paths deliberately *excluded* from
SPA navigation, so it was asserting the opposite of the intended behaviour.

**How to apply** — check each of the three before marking anything done:

- **Toothless.** Break the guard on purpose and confirm a test goes red. If
  nothing fails, the test is decoration. Verify the property and selector names
  actually exist (`window.soundchexPlayer.el`, not `.audio`); a typo in a
  `page.evaluate` silently yields `undefined` and the assertion still "passes"
  against a default. Assert access by requesting the URL, never by whether a
  link renders.
- **Redundant.** Several tests exercising one branch through different wording
  inflate the count without widening coverage. Prefer one test per distinct
  behaviour, and spend the effort on an uncovered refusal instead.
- **Gaps.** Ask what is *not* covered: the refusal paths, the degraded paths
  (no ffmpeg, storage throwing, corrupt stored JSON, missing keys from an older
  version), and the boundaries the design deliberately accepts — pin those too,
  so an intended limit is distinguishable from a regression.

Related: [[soundchex-testing-standard]], [[soundchex-plan-workflow]].
