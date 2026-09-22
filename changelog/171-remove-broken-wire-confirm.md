# 171 — Buttons that use `wire:confirm` now actually fire

The Unlink button on Integrations, and the Install/Enable buttons on Plugins,
appeared to do nothing when clicked. They used Livewire's `wire:confirm`, which
shows a browser `confirm()` dialog before the action — and that dialog is
unreliable in the desktop client's webview, silently swallowing the click. (The
Server Transfer page had already worked around this with a two-click pattern; its
comment names the same problem.)

## Fixed

- Removed `wire:confirm` from the **Unlink** (Integrations), **Install** and
  **Enable/Disable** (Plugins), and **Sign out** (Sessions) buttons. They now
  fire on click and disable themselves while the action runs (`wire:loading`).
- The trust warning for enabling a plugin is unchanged as a prominent section at
  the top of the Plugins page ("Before you enable a plugin"), so removing the
  per-button confirm loses no guidance. Unlink is reversible (paste the key back)
  and needed no confirm.

## Testing

- Integrations, Plugins and Sessions pages render with no `wire:confirm`
  remaining; the underlying `unlink`/`toggle`/`install`/`revoke` actions were
  already correct and are now reachable.
