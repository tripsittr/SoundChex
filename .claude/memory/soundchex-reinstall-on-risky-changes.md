---
name: soundchex-reinstall-on-risky-changes
description: Full delete-and-reinstall of the iOS app for changes that could break it; over-the-air update when the fix can travel that way
metadata:
  type: feedback
---

For SoundChex changes that could break the app, do a full app delete and
reinstall rather than installing over the top. When the fix is one that can
travel as an ordinary update — anything served from the server, such as the
service worker, CSS, JS or Blade output — push it that way instead and say so.

The user tests on an **iPhone 16 Pro**. Reported bugs are from that device
unless stated otherwise, so reproductions should use its viewport and Safari.

**Why:** Tauri compiles the frontend into the binary, so a stale install keeps
serving old assets and a reinstall is the only way to be sure which code is
running. But a full reinstall is slow and manual, so it is wasted effort for
fixes the running app would pick up on its own.

**How to apply:** Before saying a fix is verified on device, state which path it
takes — reinstall or update. If a reinstall is needed, say so explicitly rather
than leaving the user to guess why the fix has not appeared. See
[[soundchex-testing-standard]].
