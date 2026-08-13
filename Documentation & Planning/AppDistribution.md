# App distribution paths

Getting SoundChex onto the four targets that matter here — **Windows, Linux,
iOS and TV** — preferring sideloading over app stores.

Written 13 August 2026. Prices are what they cost at that date.

**Two decisions already made**, which shape everything below:

- **Apple's $99/yr developer account is acceptable.** This removes the single
  biggest obstacle in a store-free setup: free provisioning expires every 7
  days, so an unpaid iOS app stops launching weekly. Paying extends signing to
  a year and makes **Apple TV** a real option on the same account.
- **Tauri v2 is the chosen starting point**, covering **macOS, Windows, Linux,
  iOS and Android** from one codebase — every target here except TV. TV is
  handled separately because no TV platform shares a runtime with it.

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

Ordered by value for these four targets.

| # | Path | Covers | Cost | Effort |
| --- | --- | --- | --- | --- |
| **1** | **PWA (built)** | iOS, Windows, Linux, Tizen/webOS | $0 | Done |
| **2** | **Tauri v2** | macOS, Windows, Linux, **iOS**, Android | $0 + Apple fee | 2–3 days |
| **3** | **Android TV / Fire TV** | TV | $0 | 3–5 days |
| **4** | **Tizen / webOS** | Samsung / LG TVs | $0 | 2–4 days each |
| **5** | **Apple TV (tvOS)** | TV | Covered by Apple fee | 1–2 weeks |
| **6** | **Jellyfin-compatible API** | Every TV at once, via others' apps | $0 | Weeks |

**Suggested order: 1 → 2 → 3, then decide on TV.**

The uncomfortable truth about TV: **there is no single path that covers Android
TV, Apple TV and Samsung/LG.** They share no runtime, no language and no
store-free install method. Options 3–5 are three separate projects; option 6 is
one project that reaches all of them by pretending to be Jellyfin. If all three
TV platforms genuinely matter, **read option 6 first** — it is the only answer
that does not triple the work.

---

## 1. PWA — already built, and it covers three of the four

Add to Home Screen on iOS; Install from Chrome/Edge on Windows and Linux.
Samsung and LG TVs render web apps natively, so the browser is a real entry
point there too.

**Pros**

- Zero cost, zero new code, no store, no signing, nothing to maintain.
- One deploy updates every platform at once.
- Downloads and background audio already work.
- The transcode pipeline already normalises to browser-safe codecs (H.264/AAC),
  which is exactly what constrained TV webviews need.

**Cons**

- **iOS storage is capped at roughly 1 GB** and evicted aggressively.
  `navigator.storage.persist()` is granted far more readily to an installed app
  than a tab — the practical reason to add to home screen. This cap is the main
  argument for option 2.
- Install on iOS is Safari-only; Chrome on iOS cannot do it.
- No launcher icon on a TV.

**Worth doing regardless:** `apple-touch-startup-image`, and a one-time
"add to home screen" hint on first iOS visit. Hours of work, and it improves
the path most people will actually use.

---

## 2. Tauri v2 — every non-TV platform from one codebase

A Rust shell around each platform's native webview, pointed at the Tailscale
URL. v2 (stable since Oct 2024) targets iOS and Android as well as all three
desktops. **Rust is already installed on this machine.**

This is the best single investment on the page — it is the chosen starting
point.

**Pros**

- **macOS, Windows, Linux, iOS and Android from one codebase.**
- **Escapes the iOS 1 GB cap** — a native shell gets real storage, real
  background audio, and lock-screen controls.
- With the paid Apple account, **signing lasts a year**, so the app stays
  installed instead of dying weekly.
- Tiny: ~10 MB installers, versus ~150 MB for Electron.
- Distributed as a plain download from GitHub Releases. No store.

**Cons**

- **iOS builds require a Mac with Xcode** — you have the Mac, Xcode is a large
  install but free.
- **Unsigned Windows binaries warn once** — SmartScreen "unrecognised app".
  One-time, dismissable, and ~$100–300/yr to remove. Not worth it for household
  use; just click through.
- Linux uses WebKitGTK, which lags on codec support. The existing transcode
  pipeline already mitigates this.
- Mobile targets are younger than desktop ones — rougher edges, thinner docs.

**Build once per OS:** Tauri cross-compiles poorly, so Windows binaries want a
Windows machine (or CI) and Linux wants Linux. macOS and iOS build here. GitHub
Actions runners cover all three desktops free for public repositories, which is
the usual way to avoid keeping three machines.

---

## 3. Android TV / Fire TV — the most tractable TV

Sideloading is officially supported: `adb install`, or the Downloader app on
Fire TV. No store, no account, no fee.

**Pros**

- Free, and the normal way people load these devices.
- Fire TV Sticks are cheap enough to buy one purely to test on.
- A web-based shell is a viable starting point.

**Cons**

- **A remote is not a touchscreen.** D-pad focus management is a genuine
  redesign — every control needs a visible focus state and a sensible traversal
  order. **This is the actual work; the packaging is trivial by comparison.**
- Fire TV hardware is memory-constrained; a heavy web view struggles.
- Leanback launcher integration needs a little native code.

---

## 4. Samsung Tizen / LG webOS

Both run **web apps natively**, which suits this project better than any other
TV platform — it is closer to "host the existing frontend" than to writing an
app.

**Pros**

- No native language to learn; both are HTML/JS platforms.
- Free SDKs (Tizen Studio, webOS CLI) and free developer modes.
- The existing responsive UI and browser-safe codecs are already most of the way
  there.

**Cons**

- **Developer mode expires every 50–60 days** and must be re-enabled by hand on
  the TV itself. This is the real cost — it never stops needing attention.
- Two separate SDKs, two packaging formats, two sets of quirks.
- Old TVs ship old webviews; expect missing APIs and to check IndexedDB support
  specifically, since downloads depend on it.
- Same D-pad focus redesign as option 3.

**Verdict:** reasonable if a Samsung or LG set is your main screen. The
recurring re-enable is what makes it tiring rather than hard.

---

## 5. Apple TV (tvOS)

Now viable given the paid account, where it was not before.

**Pros**

- Same $99/yr account as iOS, so no additional cost.
- Signing lasts a year rather than 7 days.

**Cons**

- **tvOS has no web view for app content.** There is no wrapping a website —
  this means a real native app in Swift, or TVML, and it is the only target
  here that cannot reuse the existing frontend at all.
- **1–2 weeks minimum**, and a separate UI to maintain forever.
- Sideloading needs the device paired to Xcode.

**Verdict:** the most expensive target by far, in effort rather than money.
Do it only if Apple TV is a primary screen — otherwise option 6 reaches it for
a fraction of the work.

---

## 6. Jellyfin-compatible API — the one path that covers every TV

Implement enough of Jellyfin's HTTP API that existing Jellyfin clients talk to
SoundChex. Those clients already exist, tested and maintained, on **Android TV,
Fire TV, Apple TV, Roku, Samsung and LG** — every TV target on this page.

**Pros**

- **One server-side effort reaches every TV platform at once**, including tvOS,
  where it replaces 1–2 weeks of Swift with no client code at all.
- Someone else wrote, tested and maintains the clients, including all the D-pad
  focus work that dominates options 3–5.
- Nothing to sideload, sign, or re-enable every 60 days.
- It is server code — the part of this project that is already well tested.

**Cons**

- **Weeks of work.** The API is large and only partly documented.
- **The data models do not line up.** Books have no Jellyfin equivalent, and
  the per-profile permission and rating-cap model would need mapping onto
  Jellyfin's users — the rating cap especially, since a leak there is a real
  failure and the client is not yours to fix.
- Client behaviour varies, and debugging someone else's client is unpleasant.
- Any client update can break assumptions.

**Verdict:** the strongest option if all three TV platforms genuinely matter.
Compare honestly: options 3+4+5 total roughly 3–5 weeks across three separate
codebases, each needing its own focus redesign and its own re-signing ritual.
This is a few weeks in one codebase, in the language this project is already
written and tested in. **The catch is that books and per-profile permissions
likely do not survive the translation** — so it is a media-playback answer, not
a whole-app answer.

---

## Recommended plan

1. **Polish the PWA** (hours) — iOS install hint and startup images. Improves
   the path most-used today and costs almost nothing.
2. **Tauri v2** (2–3 days) — macOS, Windows, Linux, iOS and Android in one go,
   escaping the iOS storage cap. Rust is already here; Xcode is the only new
   install for the Apple targets.
3. **Buy the Apple account** when starting step 2, not before — the year of
   signing starts ticking from purchase.
4. **Then decide TV deliberately**, because this is where the money in effort
   goes:
   - *Only Android TV / Fire TV matters* → option 3, 3–5 days.
   - *All three TV platforms matter* → option 6, and skip 3–5 entirely.
   - *Apple TV is the main screen* → option 5, accepting it is the most
     expensive thing on this page.

## What to avoid

- **Code-signing certificates for Windows.** The warning is one click, and the
  money is better spent on a Fire TV Stick to test against.
- **Electron**, now that macOS is not a target — it is ~150 MB and ~200 MB RAM
  to do what Tauri does in ~10 MB, with the same signing warnings.
- **Capacitor**, which overlaps Tauri v2 almost entirely. Two toolchains for
  one outcome.
- **Rewriting the frontend natively** (React Native, Flutter). It would
  duplicate a working, tested UI to solve a distribution problem, and leave two
  frontends to keep in step.
- **Starting three TV projects at once.** Pick one platform or pick option 6.
