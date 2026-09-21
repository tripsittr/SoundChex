<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) 2026 SoundChex
-->

# Client plugins — Phase 1 specification

**Status: specification. Nothing here is built yet.** This is the plan of record
(tracker S-283), written now so the client apps are built to host it. The
server-side plugin platform it extends is real and shipped — see
[README.md](README.md); read that first, because a client plugin reuses its
manifest, catalogue, version gating and trust model wholesale.

The one-line version: **a client plugin is sandboxed HTML/CSS/JS that the app
runs in its own web engine and talks to the SoundChex server through a tiny,
audited host object.** One format runs on every client — iOS, Android, desktop —
and no client plugin ever touches a native device API.

---

## Why sandboxed JS, and why one format

A server plugin is PHP that runs in the server process with full access. That
model cannot cross to the clients, for one hard reason and one soft one:

- **The hard reason is the iOS App Store.** Guideline **2.5.2** forbids an app
  from downloading and running native code at runtime. The only carve-out,
  **4.7**, is for "mini apps and games": HTML5/JS executed by the system web
  engine, with **no native bridge** — no device APIs, no custom
  `WKScriptMessageHandler` surface exposed to the plugin, no alternative JS
  engine, and the content served from a manifest behind an age gate. A
  native-per-platform plugin therefore cannot be user-installable on iOS at all.
- **The soft reason is that we do not want three plugin ecosystems.** A developer
  who writes a "recently-added shelf" plugin should write it once.

Sandboxed JS satisfies 4.7 *and* is the same technology every other client can
host (a system WebView on Android and desktop, WKWebView/JavaScriptCore on
iOS/macOS). So it is the shippable cross-platform answer, and it is the only one.
This is the same bet Jellyfin, Emby and VS Code make for their client/extension
surfaces.

**Consequence, stated plainly:** a client plugin has **no** access to the device
— no filesystem, no camera, no contacts, no push, no Bluetooth, no native UI. It
renders web content and calls the server. Anything needing device access is a
native app feature, not a plugin. We do not consider this a limitation to work
around; it is the boundary that makes plugins safe to install and possible to
ship.

---

## The server is the backend

A client plugin has no backend of its own. **The SoundChex server is its
backend.** Everything a plugin does that touches data goes through the server's
existing JSON API, scoped to the signed-in profile and its abilities — the same
API the native apps already use.

This is deliberate and load-bearing:

- There is no new trust surface. A plugin can do only what the current profile
  can already do; a kids profile's plugin sees a kids library.
- A plugin cannot reach another server, a tracker, or an ad network — network
  egress is not part of the host object (see `sc.api` below). It talks to *this*
  server or nowhere.
- Server-side plugins (S-264) can add new API-shaped capabilities that client
  plugins then consume. The two platforms compose: a server plugin exposes data;
  a client plugin renders it.

---

## The host object: `sc`

The plugin's JavaScript is handed exactly one global, `sc`, and nothing else that
is ours. It is small on purpose — minimal-first, grown only when a real plugin
needs more, never speculatively. Everything a plugin is allowed to do is a method
on `sc`; if it is not on `sc`, it cannot be done.

```
sc.api      — session-scoped calls to the SoundChex server API
sc.html     — safe rendering into the plugin's slot
sc.store    — sandboxed, per-plugin persistent state
sc.ui       — a few host-drawn primitives (toast, navigate)
sc.context  — read-only facts about where the plugin is running
```

There is no `sc.device`, no `sc.fetch`, no `sc.eval`, no handle to the DOM
outside the plugin's own slot, and no native message channel. That absence is the
security model.

### `sc.api` — the server, and only the server

```js
const page = await sc.api.get('/library', { type: 'music', limit: 50 })
await sc.api.post('/items/1234/progress', { position: 42, duration: 200 })
```

- Calls are **relative paths only**, resolved against the server the app is
  already signed in to. An absolute URL is rejected; there is no way to name
  another host. This is what keeps a plugin from phoning home.
- Every call carries the **current session's auth and profile** automatically —
  the plugin never sees a token and cannot forge one for a different profile.
- The server applies its own gate. A call the profile is not allowed to make
  fails with the same error a native client would get; the plugin cannot escalate
  by asking.
- The reachable routes are an **allowlist** the host enforces, not the whole API.
  Phase 1 allows the read/playback/search surface the native apps use:

  | Method | Path | For |
  |---|---|---|
  | GET | `/me` | who is signed in |
  | GET | `/library` | browse the library (paged, filterable) |
  | GET/POST | `/library/delta` | incremental library sync |
  | GET | `/items/{item}` | one item's detail |
  | GET | `/items/{item}/progress` | resume position |
  | POST | `/items/{item}/progress` | save a resume position |
  | GET | `/items/{item}/lyrics` | lyrics |
  | GET | `/search` | library search |
  | GET | `/profiles/mine` | this account's profiles |
  | GET | `/notifications` | the in-app event log |
  | GET | `/playlists` | playlists |

  Streaming (`/items/{item}/stream`) is reached only through `sc.ui.play(item)`
  (Phase 2+), never as raw bytes to plugin JS. Admin routes are **never** on the
  client allowlist — administering the server is not a plugin's job. A server
  plugin (S-264) that adds a route can add it to this allowlist through a
  server-side declaration; a client plugin cannot widen its own allowlist.

### `sc.html` — rendering without an injection hole

A plugin renders into its slot through a safe interface, never by touching a raw
DOM the host also lives in.

```js
sc.html.render(sc.html`
  <ul class="shelf">
    ${items.map(i => sc.html`<li>${i.title}</li>`)}
  </ul>
`)
```

- `sc.html` is a tagged-template builder that **escapes interpolations by
  default** — a title holding `<script>` renders as text, not markup. This is the
  one rendering path; there is no `innerHTML` escape hatch handed to the plugin.
- The plugin's markup is confined to its slot's shadow root, so a plugin cannot
  restyle or read the rest of the app.
- A small, themed CSS surface (the app's tokens) is available so plugins look
  native without shipping a design system.

### `sc.store` — state that stays in its box

```js
await sc.store.set('lastTab', 'albums')
const tab = await sc.store.get('lastTab')
```

- Per-plugin, per-profile key/value storage, sandboxed so one plugin cannot read
  another's. Small quota (a few hundred KB); it is for preferences and light
  caches, not a database.
- Where it physically lives is the host's choice (a keyed store on each platform);
  the plugin sees only `get`/`set`/`delete`/`keys`.
- It is **not** shared with the server or across devices in Phase 1. A plugin that
  needs durable, synced state writes it through `sc.api` to a server plugin's
  endpoint.

### `sc.ui` — a few host-drawn primitives

The host draws the things that must feel native and stay consistent:

```js
sc.ui.toast('Added to your queue')
sc.ui.navigate('item', { id: 1234 })   // hand off to the app's own item screen
sc.ui.play(item)                         // Phase 2+: start playback in the native player
```

Phase 1 ships `toast` and `navigate`. `play` arrives with Phase 2 (it needs the
streaming hand-off wired per platform). A plugin never draws a system dialog,
never opens a URL in a browser, and never leaves its slot except through these
host-mediated hand-offs.

### `sc.context` — read-only facts

```js
sc.context.platform   // 'ios' | 'android' | 'desktop'
sc.context.slot       // where this instance is mounted (see the slot catalogue)
sc.context.profile    // { id, name, isKids } — never a token
sc.context.theme      // 'light' | 'dark'
```

Read-only and non-identifying: enough for a plugin to adapt its layout, nothing a
plugin could use to fingerprint the device.

---

## Slots — where a plugin appears

A client plugin does not draw anywhere it likes; it fills a **slot** the app
offers. Slots are the client equivalent of the server's seams — a fixed,
versioned catalogue the host controls, so a plugin can never occupy space the app
did not offer it. A plugin declares the slots it fills in its manifest and
provides a render function per slot.

Phase-1 slot catalogue (platform-agnostic; each client maps them to its own
navigation):

| Slot | Where it appears | Example |
|---|---|---|
| `home.shelf` | A row on the home screen | "Recently added", "Forgotten favourites" |
| `item.panel` | A panel on an item's detail screen | Extra metadata, a lyrics view, external links |
| `library.tab` | A whole tab in the library area | A custom browse-by-mood view |
| `settings.page` | A page under the app's plugin settings | The plugin's own preferences |

A slot has a **contract**: what `sc.context` carries when it mounts (e.g.
`item.panel` mounts with the item id), how much space it gets, and when it is
asked to render or torn down. Slots are versioned with the client-plugin API (see
below) so adding a slot is a minor bump and never breaks an existing plugin.

---

## The manifest

A client plugin reuses the server manifest (see [README.md](README.md#the-manifest-pluginjson))
and adds a client block. The shared fields — `id`, `name`, `version`, `author`,
`description`, `license`, `targetApi` — mean exactly what they do server-side, so
one catalogue and one trust model cover both.

```json
{
  "id": "acme.recently-added",
  "name": "Recently Added Shelf",
  "version": "1.0.0",
  "author": "You",
  "description": "A home-screen shelf of the newest additions to your library.",
  "license": "AGPL-3.0-or-later",
  "client": {
    "runtime": "js",
    "targetClientApi": "1.0.0",
    "entry": "index.js",
    "slots": ["home.shelf"],
    "minClientApp": { "ios": "1.0.0", "android": "1.0.0", "desktop": "1.0.0" }
  }
}
```

- `client.runtime` is `"js"` — the only runtime, named so the catalogue can tag
  and filter by it.
- `client.targetClientApi` is the **client-plugin API version** the plugin was
  built against — the client analogue of the server's `targetApi`, with the same
  survival rule: a plugin survives every client-app release sharing its
  client-API **major**, and is refused only on a major overhaul or when it targets
  a newer client API than the app ships (see
  [Surviving server versions](README.md#surviving-server-versions) for the
  mechanism, which is identical).
- `client.slots` lists the slots it fills; each needs a matching export in
  `entry`.
- `client.minClientApp` gates on each native app's own version, for the rare case
  a plugin needs a capability only newer apps have. Omitted means "any app new
  enough for this client API".

A plugin may carry **both** a server `entrypoint` and a `client` block — the same
package delivering a server seam and the client UI that renders it. The server
loads one half; each client loads the other.

---

## One catalogue, tagged by runtime

There is no separate client store. The existing plugin catalogue (S-264) lists
client plugins alongside server ones, each tagged by what it provides —
`server`, `client`, or both. A client filters the catalogue to plugins whose
`client.runtime` it can host and whose client-API major it supports, and shows
those. Install, checksum verification, version gating, the disabled-by-default
posture, and the at-your-own-risk note on third-party repositories are all reused
unchanged.

The one addition is **serving**: a client asks the server for the client plugins
it should run and their code (Phase 2, below). On iOS this serving is also what
satisfies 4.7's "content manifest": the app does not sideload arbitrary code, it
loads a catalogue the server vends.

---

## Trust, restated for clients

The server plugin platform's honesty note (README) says a server plugin is not
sandboxed and runs with full access. **A client plugin is the opposite: it is
sandboxed, and that is the whole point.** The guarantees:

- No device access. The plugin sees `sc` and its own slot's shadow root, nothing
  else — no filesystem, no native APIs, no other plugin's state.
- No arbitrary network. `sc.api` reaches this server on an allowlist; there is no
  general `fetch`. A plugin cannot exfiltrate a library to a third party because
  it has nowhere to send it.
- No privilege escalation. Every server call runs as the current profile with its
  abilities; the plugin cannot act as another profile or as an admin.
- Installed disabled, enabled by a deliberate act, killable by a master switch —
  the same posture as server plugins.

The threat a client plugin *can* pose is a bad in-slot experience (a broken
shelf, an ugly panel), not a compromised device or a leaked library. That is a
risk worth taking for an extensible client; the boundaries above are what keep it
at that level.

---

## Phases

Phase 1 (this document) is buildable now and platform-agnostic. The rest wait for
the native apps to be ready to host a runner.

1. **Spec + `sc` host contract + slot catalogue** — *this document*. The
   platform-agnostic definition every host implements against. **Buildable now.**
2. **Server endpoints** to serve and scope client plugins: list the client
   plugins a profile should run, vend their code as a content manifest, enforce
   the `sc.api` allowlist server-side. Reuses the catalogue and installer.
3. **Reference host on desktop (Tauri)** — the first real runner, in a system
   WebView, where iteration is cheapest and the App Store rules do not bind. This
   is where the `sc` contract gets proven against real plugins.
4. **iOS + Android hosts** — WKWebView/JavaScriptCore on iOS/macOS (built to 4.7:
   content manifest, age gate, no native bridge beyond the audited `sc`), the
   system WebView on Android. The `sc` contract is identical; only the host
   plumbing differs per platform.
5. **Docs + a client scaffold** in `plugin:make` (a `--client` flag that writes a
   working `home.shelf` plugin), so a developer starts from a running example.

## Open questions, and where they landed

- **How big is `sc` at launch?** Minimal-first: the surface above and no more.
  Grown only when a shipped plugin demonstrably needs it. *(Decided: minimal.)*
- **Separate client catalogue, or one tagged catalogue?** One catalogue, tagged
  by runtime. *(Decided: one.)*
- **Write the Phase-1 spec now or hold for the apps?** Now — so the apps are
  built to host it rather than retrofitted. *(Decided: now — this document.)*
- **Still open for Phase 2:** the exact content-manifest shape iOS 4.7 wants, and
  whether `sc.store` gains an opt-in server-synced tier. Both are deferred to when
  Phase 2 starts; neither blocks Phase 1.
