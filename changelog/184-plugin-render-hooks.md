# 184 — Plugins can put UI inside the panel, not just beside it

**Merged** 2026-09-23 · **Issues** S-316

A plugin could already contribute a whole admin page. It could not add anything
*to* an existing one. Two seams close that: markup at a named position in the
panel's chrome, and a dashboard widget.

## What changed

### `Registry::renderHook()`

```php
$registry->renderHook(
    PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
    fn (): string => view('your-plugin::banner')->render(),
);
```

Filament exposes fixed positions in its chrome; this lets a plugin write into
one without the panel knowing the plugin exists. Registered after
`bootLoaded()`, because that is when a plugin's `boot()` runs and registers its
hooks — applying them any earlier would apply an empty list.

Each hook is wrapped: a plugin that throws renders nothing at that position and
the failure is logged, rather than taking the page down with it. That is the
same posture the routes and migrations seams already take.

### `Registry::widget()`

A dashboard widget, handed to the panel the same way plugin pages are —
discovery cannot find a class outside the app's namespace. Registered only
while the plugin is enabled.

## Worth knowing

- **A plugin's markup still cannot use the app's Tailwind classes**, for the
  reason in AGENTS.md: the app's CSS is compiled before a catalogue-installed
  plugin exists. Filament's own components are safe, and a plugin's own
  stylesheet is compiled on enable (S-350). The docs say so at the seam, where
  someone is about to write markup, rather than only in a warnings section.
- Tests: **918 of 924**, the same two pre-existing failures. Five new, plus an
  end-to-end check that markup registered through the seam actually appears in
  the rendered panel — registration alone would not have proved it.

## Still wrong

- Filament's hook names are strings at heart; passing the `PanelsRenderHook`
  constant is a convention the seam documents but cannot enforce.
- No seam yet for the *user-facing* player — that is S-318, and it needs slots
  placing deliberately in the Blade templates rather than a wrapper around
  something Filament already provides.
