# 079 — Investigate + close old issues; wire up OpenSubtitles key

**Merged** 2026-09-17 · **Issues** S-02, S-03, S-12, S-13

Investigated the older open issues and marked each by what is actually true —
plus one small real fix that let S-12 close.

## What changed

### OpenSubtitles key on the Integrations page (closes S-12)

`OpenSubtitles` already read `opensubtitles_api_key` from `SettingsService`
(`app/Services/Subtitles/OpenSubtitles.php:271`) — it just had no field on the
Integrations page, so the key could only be set via `.env`. Added the row; now
all three sources S-12 named (AcoustID, Spotify, OpenSubtitles) are
key-configurable from the UI, and skipped sources are logged (`MetadataPipeline`
warns which skipped and why). S-12 → Done.

### Closed as verified

- **S-13** — items 1445, 2002, 2473 still carry `match_confidence = none` in the
  live DB. Complete files with no provider match; a factual state, not a fixable
  bug. → Done (not a defect).

### Annotated (partially superseded, kept for the web/desktop surface)

- **S-02** (reader slow) — the mobile answer is the native reader, IOS-08; this
  row now scopes to the web/desktop reader.
- **S-03** (artist/album/item not in the mirror) — the native iOS app builds
  these from the mirror + API, so the ~900ms relay round-trip no longer applies
  on the native client; the row now scopes to the web/desktop mirror.

## Tests

`php -l` clean. The one failing `IntegrationsPageTest` assertion
(`test_metadata_providers_are_listed_but_not_editable_here`, a Filament HTML
render check) **fails on `main` too** — verified by stashing — and is unrelated
to this change.
