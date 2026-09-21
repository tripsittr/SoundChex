# 144 — Client-plugin platform: Phase 1 specification

The plugin platform (S-264) runs on the server. This writes the plan of record
for extending it to the **clients** — plugins that run inside the iOS, Android and
desktop apps — so the apps are built to host it rather than retrofitted later.

**Specification only. No client-plugin code ships here.** The deliverable is
`docs/plugins/CLIENT-PLUGINS.md` (tracker S-283).

## What it decides

- **Model: sandboxed HTML/CSS/JS**, one format on every client, run in each app's
  own web engine. Driven by the hard constraint that iOS App Store 2.5.2 forbids
  running downloaded native code; the 4.7 carve-out is JS in the system web engine
  with no native bridge. Sandboxed JS is the only user-installable, cross-platform
  answer.
- **The server is the plugin's backend.** A client plugin has no backend of its
  own and no device access — it renders web content and calls the existing JSON
  API, scoped to the current profile. No new trust surface.
- **The host object `sc`** is the whole of what a plugin may do: `sc.api`
  (relative-path, session-scoped server calls on an allowlist — no general
  `fetch`, so a plugin cannot phone home), `sc.html` (escaped-by-default rendering
  into a shadow-root slot), `sc.store` (sandboxed per-plugin state), `sc.ui` (a
  few host-drawn primitives), `sc.context` (read-only, non-identifying facts).
  Minimal-first: nothing device-shaped, grown only when a real plugin needs it.
- **Slots** are the client seams — a fixed, versioned catalogue (`home.shelf`,
  `item.panel`, `library.tab`, `settings.page`) a plugin fills; it can never draw
  where the app did not offer.
- **Reuses S-264 wholesale**: the manifest (plus a `client` block with
  `targetClientApi` mirroring the server's version-survival rule), one catalogue
  tagged by runtime, checksum + version gating, disabled-by-default, the master
  switch.

## Phases logged (build later)

1. Spec + `sc` contract + slot catalogue — **this document, buildable now.**
2. Server endpoints to serve and scope client plugins (also the iOS content
   manifest).
3. Reference host on desktop (Tauri) — proves the `sc` contract first where
   iteration is cheapest.
4. iOS + Android hosts (WKWebView/JavaScriptCore, system WebView) built to 4.7.
5. Docs + a `plugin:make --client` scaffold.

The allowlisted API routes in the spec were checked against `routes/api.php` — all
nine exist in the authenticated, non-admin group.

## Also

Linked the new spec from `docs/plugins/README.md` so the server and client
platforms cross-reference.
