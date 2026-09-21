# 136 — Notification targets are a plugin seam (S-264, #281)

Notification delivery was three hardcoded webhook destinations — Discord, Slack,
and a generic hook. A plugin can now add a notification target of its own,
delivered alongside the built-ins, so a notification can reach somewhere the core
does not (Telegram, ntfy, Pushover). This is what Emby ships as plugins; ours now
does too.

## What this adds

- **A `NotificationTarget` contract** (`App\Plugins\Contracts\NotificationTarget`)
  — `name()`, `isConfigured()` (the cheap gate), and `send(Notification)`, which
  must swallow its own failures.
- **`Registry::notificationTarget($class)`** — the seam a plugin registers on.
- **`WebhookNotifier` dispatches to plugin targets** after the built-in
  destinations. `hasDestinations()` now also counts a configured plugin target,
  so a notification is delivered even when no built-in webhook is set. A target
  that throws is logged and skipped — one plugin cannot break the others or the
  event that recorded the notification. The built-in path is unchanged; this is
  purely additive.
- **A worked example** (`plugins/examples/telegram-notify`) — a real Telegram
  target reading a bot token and chat id from settings, posting the notification
  to a chat, with failures swallowed.

## Tests

- `PluginNotificationTargetTest` — a configured target receives the notification;
  an unconfigured one is skipped; `hasDestinations()` is true when only a plugin
  target is configured; a throwing target does not break the send and the next
  still runs.
- Full suite: 792 passed (the 15 existing webhook tests unchanged).

## Arc closed

This completes the "core features as plugins" arc from #279–282: the title tidier
(#279) and the two new seams — cover sources (#280) and notification targets
(#281) — are done; the built-in metadata sources (#282) were deferred as
low-value churn. The app's own opinions are now the platform's plugins, and each
seam ships with a worked example an author can copy.
