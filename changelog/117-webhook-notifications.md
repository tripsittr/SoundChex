# 117 — Webhook notifications (Discord, Slack, generic hooks)

Server events can now be pushed to outside chat and notification services.
Closes **S-262** (Discord/Slack) and **S-263** (notification hubs) together,
because they are the same mechanism with different payload shapes.

## What this adds

- **`WebhookNotifier`** — one service, three destinations:
  - **Discord** — posts an embed (title, body, SoundChex accent colour).
  - **Slack** — posts text plus a coloured attachment.
  - **Generic** — a flat `{title, message, source}` JSON POST that ntfy,
    Apprise, Gotify and custom endpoints accept.

  Each destination is a single URL, off until one is saved, and formats the
  same event to suit itself.

- **Fired on real events.** The notifier is wired into
  `Notification::record()`, so anything that records an in-app notification —
  a finished library scan, a new episode of a followed series, a failed
  download — also reaches every configured webhook, with nothing extra to
  call at each event site.

- **Off the request.** Delivery runs in `SendWebhookNotificationJob` on the
  queue (`tries = 2`, 30s backoff). A slow or dead endpoint can never hold up
  the scan or download that triggered it, and a failed send is logged and
  swallowed rather than bubbled up.

- **Configured on the Integrations page.** Discord, Slack and Webhook appear
  as cards under a new **Communication** group. Each opens a modal with a
  single **Webhook URL** field, a per-service hint on where to find that URL,
  and a **Send test** button that pings the endpoint and reports whether it
  was accepted.

## Notes / still open

- The webhook fires on the events `Notification::record()` already emits
  (scan finished, episode added, download failed). Broader event coverage —
  **download *finished* (success)**, new-media-added, transcode-finished and a
  periodic server-health ping — is logged separately in the Tracker as
  follow-up event sources, not built here.
- Webhook URLs are stored via `SettingsService` unencrypted (a webhook URL is
  a capability, not a secret in the same sense as an API key; it is shown back
  in the field so a wrong path can be corrected without retyping).

## Tests

- `WebhookNotifierTest` — record dispatches delivery only when a hook is set;
  posts to every configured destination; Discord embed shape; a dead endpoint
  does not throw; `test()` reports acceptance/rejection.
- `IntegrationsPageTest` — Communication webhooks are listed; saving stores the
  URL; an invalid URL is rejected; the test button pings the endpoint.
- Full suite: 688 passed.
