# App distribution paths

Options for getting SoundChex onto phones, desktops and TVs **without app
stores** — no developer accounts, no review queues, no annual fees.

Written 13 August 2026. Prices are what they cost at that date; the free tiers
are the ones that matter here, and those change rarely.

## The starting position

This matters, because it makes several of the options below nearly free:

- The server is a **Laravel app already usable in a browser**, reachable from
  anywhere over Tailscale HTTPS.
- **A PWA already exists** — `public/manifest.webmanifest` (standalone display,
  192/512/180 icons) and `public/sw.js` (caches build assets and artwork; never
  HTML, never audio, because range requests break in the Cache API).
- **Offline downloads already work**, stored as blobs in IndexedDB and played
  from object URLs. This is covered by a browser test that cuts the network.
- The media center is **mobile-laid-out already**, with a persistent player
  that survives navigation.

So the question is not "how do I build an app" — it is "how much native shell
is worth adding around something that already runs."

---

## Summary

| Path | Platforms | Cost | Effort | Store needed |
| --- | --- | --- | --- | --- |
| **1. PWA (already built)** | Phone, desktop, some TV | **$0** | **Done** | No |
| **2. Tauri v2** | Desktop **+ Android + iOS** | $0 unsigned | 1–2 days desktop | No (except iOS) |
| **3. Electron** | Win/macOS/Linux desktop | $0 unsigned | 1 day | No |
| **4. Capacitor + sideload** | Android (+iOS, painfully) | $0 Android | 2–4 days | No (Android) |
| **5. TWA via Bubblewrap** | Android | $0 | ~half a day | No, if sideloaded |
| **6. Android TV / Fire TV** | TV | $0 | 3–5 days | No (sideload) |
| **7. Jellyfin-compatible API** | Everything, via other apps | $0 | Weeks | No |
| **8. Kodi add-on** | TV, desktop, Pi | $0 | 1–2 weeks | No |

**Recommendation: 1 → 5 → 2 → 6.** The PWA is done; Bubblewrap turns it into a
real sideloadable Android app in an afternoon; Tauri gives a proper desktop app
for a weekend; TV is the only place needing genuine new work.

**Tauri v2 alone covers everything here except TV** — desktop, Android and iOS
from one codebase — so it is the single best answer if you would rather learn
one toolchain than three. It is not listed first only because Bubblewrap gets
a working Android app in a fraction of the setup, and iOS remains gated by
Apple no matter which of these you choose.

---

## 1. PWA — already built

Add to Home Screen on any phone, Install on desktop Chrome/Edge.

**Pros**

- Zero cost, zero new code, no store, no signing.
- One codebase; a server deploy updates every "app" at once.
- Downloads and background audio already work.

**Cons**

- **iOS is the weak spot.** Safari caps storage at roughly 1 GB and evicts
  aggressively; `navigator.storage.persist()` is granted far more readily to an
  installed app than a tab, which is the practical reason to recommend
  add-to-home-screen. Install is Safari-only — Chrome on iOS cannot do it.
- No app icon in a TV launcher.
- Users have to type a URL once.

**Worth doing anyway:** an `apple-touch-startup-image`, and a short
"add to home screen" hint on first mobile visit.

---

## 2. Tauri v2 — desktop *and* mobile from one codebase

A Rust shell around each platform's native webview, pointed at the Tailscale
URL. **Since v2 (stable, Oct 2024) this targets Android and iOS too**, not just
desktop — `tauri android init` and `tauri ios init` alongside the desktop
build. So this one option covers everything on this page except TV.

Rust is already installed on this machine, so the desktop path starts today.

**Pros**

- **Five platforms, one codebase**: Windows, macOS, Linux, Android, iOS.
- Tiny: ~10 MB installers, versus ~150 MB for Electron.
- Genuinely free and open source; no account of any kind to build.
- Real OS integration — tray icon, media keys, native notifications.
- Distributed as a plain download from GitHub Releases, or a sideloaded APK.
- Unlimited storage on Android, escaping the iOS 1 GB browser cap on that side.

**Cons — and these are platform rules, not Tauri's doing**

- **Unsigned desktop binaries warn on first launch.** Windows SmartScreen says
  "unrecognised app"; macOS needs right-click → Open. One-time and not
  blocking, but alarming to anyone but you.
- **iOS still needs a Mac, Xcode, and hits the same 7-day expiry** on a free
  provisioning profile. Tauri changes *what you write*, not what Apple
  permits — the app stops launching after a week either way. There is no Xcode
  on this machine today.
- **Android needs the SDK and NDK installed** (neither is here yet) — a large
  download and the fiddliest part of the setup.
- Linux uses WebKitGTK, which lags on codec support.
- Mobile targets are much younger than the desktop ones; expect rougher edges
  and thinner documentation than the desktop path.

**Cost to remove the warnings:** ~$100–300/yr for an Authenticode certificate,
$99/yr for Apple notarisation. **Not worth it for household use.**

### So why is Bubblewrap still listed first for Android?

Because for *this* project they produce nearly the same result, and one is
much cheaper to get to:

|  | Tauri v2 Android | Bubblewrap TWA |
| --- | --- | --- |
| Setup | Rust + Android SDK + NDK | Android SDK only |
| Time to first APK | A day or so | An afternoon |
| Result | Native shell, unlimited storage | Chrome full-screen, browser limits |
| Maintenance | Rebuild to ship shell changes | None — loads the live site |

Tauri is the better *app*. Bubblewrap is the better *first step*, and nothing
is wasted if you later replace it — both just load the same web UI.

**Pick Tauri for Android instead if** browser storage limits start biting, or
you want lock-screen controls and native downloads. That is the same reason
Capacitor is listed below, and if you go that route Tauri largely replaces it:
one toolchain instead of two.

---

## 3. Electron — desktop, the fast option

**Pros**

- Fastest possible path: a working wrapper is genuinely ~30 lines.
- Chromium bundled, so playback behaves identically everywhere.

**Cons**

- ~150 MB per install, ~200 MB RAM idle.
- Same signing warnings as Tauri, with none of the size advantage.

**Verdict:** only if the Tauri toolchain fights back. Otherwise Tauri wins on
every axis that matters here.

---

## 4. Capacitor — phone, real native shell

Wraps the web app in a native container with plugin access to real APIs.

**Pros**

- **Unlimited storage** on Android, bypassing the iOS 1 GB cap entirely.
- Real background audio, lock-screen controls, native download manager.
- Reuses the existing frontend as-is.

**Cons**

- **iOS still requires a Mac and Xcode**, and free provisioning profiles
  **expire every 7 days** — the app stops launching until re-signed. A paid
  account ($99/yr) extends this to a year. There is no free path to a
  permanently-installed iOS app.
- Android is genuinely fine: build an APK, sideload, done.

**Verdict:** worth it for Android if PWA storage becomes limiting. For iOS,
accept the PWA unless you'll pay Apple.

**Tauri v2 does the same job** and also builds your desktop apps, so prefer it
unless you specifically want Capacitor's much larger plugin ecosystem. Two
toolchains for one outcome is not worth it here.

---

## 5. TWA via Bubblewrap — best Android effort-to-result

A Trusted Web Activity is a thin Android app that renders the PWA full-screen
in Chrome, with no browser UI at all. Google's `bubblewrap` CLI generates it.

```
npx @bubblewrap/cli init --manifest https://<host>/manifest.webmanifest
npx @bubblewrap/cli build
```

**Pros**

- **Half a day**, mostly waiting on Android SDK downloads.
- Real launcher icon, real app switcher entry, no address bar.
- Free, self-signed, sideloadable as an APK.
- Nothing to maintain — it loads the live site.

**Cons**

- Needs `assetlinks.json` served at `/.well-known/` to hide the URL bar, which
  means the Tailscale hostname is baked into the APK.
- Android only.
- Still Chrome underneath, so it inherits any browser storage limits.

**This is the highest-value next step after the PWA.**

---

## 6. TV — the only genuinely new work

### Android TV / Fire TV (recommended)

Sideloading is officially supported: `adb install`, or Downloader by AFTVnews
on Fire TV. No store, no account.

**Pros**

- Free. Fire TV Sticks are cheap and this is the normal way people load them.
- A Capacitor or TWA build is a starting point.

**Cons**

- **A remote is not a touchscreen.** D-pad focus management is a real
  redesign — every control needs a focus state and a sane traversal order.
  This is the actual work, not the packaging.
- Fire TV devices are memory-constrained; a heavy web view struggles.
- Leanback launcher integration needs native code.

### Apple TV

**Skip.** Sideloading requires Xcode and the same 7-day expiry, on a device you
cannot easily plug into a Mac. There is no practical free path.

### Samsung/LG (Tizen/webOS)

Both run web apps natively and both have free developer modes — but developer
mode **expires every 50–60 days** and must be re-enabled, and the toolchains are
poorly documented. Only worth it if that TV is the main screen.

---

## 7. Jellyfin-compatible API — the clever option

Implement enough of Jellyfin's HTTP API that existing Jellyfin clients — of
which there are dozens, on every platform including Apple TV, Roku and every
smart TV — talk to SoundChex.

**Pros**

- **One server-side effort covers every platform at once**, including the ones
  above that have no good free path.
- Clients are already written, tested and installed from official stores by
  their own developers.
- No app to build, sign, sideload or maintain.

**Cons**

- **Weeks of work**, and the API is large and only partly documented.
- Jellyfin's data model is not this one; books and the per-profile permission
  model have no clean equivalent, so some features simply would not map.
- Client behaviour varies, and debugging someone else's client is unpleasant.
- Any client update can break assumptions.

**Verdict:** genuinely the best answer for "every TV platform" — but it is a
project in itself, not a packaging step. Worth reconsidering if TV becomes the
primary way the library gets used.

---

## 8. Kodi add-on

A Python add-on talking to the existing endpoints.

**Pros**

- Kodi runs on everything — TV boxes, Fire TV, a Pi, desktop.
- Add-ons install from a zip; no store involved.
- Python, and the API surface needed is small.

**Cons**

- Requires Kodi installed and configured first.
- Kodi's UI conventions, not this project's.
- Its Python API is idiosyncratic and its docs are thin.

---

## Suggested order

1. **Polish the PWA** — iOS install hint, startup images. Hours, and it
   improves the thing most people will actually use.
2. **Bubblewrap TWA** — a real Android app for an afternoon.
3. **Tauri desktop** — a weekend, and the desktop experience stops being a
   browser tab. Rust is already installed here.
   Its Android target then becomes a natural upgrade from the TWA, reusing
   the same toolchain, if browser storage limits ever bite.
4. **Android TV** — only when TV playback genuinely matters, budgeting for the
   D-pad redesign rather than the packaging.
5. **Reconsider the Jellyfin API** if the answer to "which platforms" ever
   becomes "all of them."

## What to avoid

- **Anything requiring an Apple developer account** unless you decide to pay;
  the 7-day expiry makes free iOS sideloading unusable in practice.
- **Code-signing certificates** for household use. The warnings are ugly but
  one-time, and the money is better spent on a Fire TV Stick.
- **Rewriting the frontend natively** (React Native, Flutter). It would
  duplicate a working, tested UI to solve a distribution problem, and then
  there would be two frontends to keep in step.
