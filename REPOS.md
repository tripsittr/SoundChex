# SoundChex repositories

SoundChex is split into one repository per **toolchain that cannot build with the
others**. Platforms that share a build system and can share code live together;
platforms that don't get their own repo. Everything is **AGPL-3.0-or-later**.

| Repository | Covers | Toolchain | Distribution | Status |
|---|---|---|---|---|
| **SoundChex** (this repo) | Laravel **server** + **desktop** (macOS, Windows, Linux) | PHP/Laravel + Tauri (Rust) | GitHub releases, self-host | Active |
| **SoundChexiOS** | **iOS, iPadOS, tvOS** | Swift / SwiftUI / Xcode | App Store | Active (iOS shipping; tvOS later) |
| **SoundChexAndroid** | **Android** phone/tablet, **Android TV, Google TV, Amazon Fire TV** | Kotlin / Jetpack Compose / Gradle | Play Store, Amazon Appstore, sideload | Scaffold |
| **SoundChexTV** | **LG webOS, Samsung Tizen, Vizio SmartCast** | Web (HTML/JS/CSS) + per-vendor SDK | LG Content Store, Samsung, Vizio | Scaffold |
| **SoundChexRoku** | **Roku** | BrightScript / SceneGraph | Roku Channel Store | Scaffold |
| **SoundChexWebsite** | Marketing, docs, legal, SCNet waitlist | Laravel | Web host | Active |

## Why grouped this way

- **tvOS ships with iOS** — same Swift/Xcode toolchain and shared core
  (networking, models, playback), different (10-foot) UI. A separate tvOS repo
  would duplicate all of that.
- **Android TV, Google TV, and Fire TV are all Android** — Fire OS is an Android
  fork; Fire TV apps are Android apps. One Kotlin project builds all five Android
  form factors; they differ in UI (touch vs. leanback) and store, not toolchain.
- **webOS, Tizen, and SmartCast are web-app platforms** — one web codebase over
  the same `/api/v1/*` API, packaged three ways. Distinct from the Android TVs
  and from Roku.
- **Roku is its own world** — BrightScript/SceneGraph shares no runtime with
  anything, so it stands alone.

## What every client repo shares

- The SoundChex server's JSON API (`/api/v1/*`) — the single contract.
- AGPLv3, with the §13 network-source obligation.
- The same workflow: issues before work, a roadmap, a changelog per change.

The **public** product roadmap (what ships when, across platforms) lives on the
landing site so users can see it; each repo's own `Roadmap.md` is the detailed,
per-platform plan.
