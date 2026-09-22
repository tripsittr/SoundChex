# 156 — Time-synced lyrics (server + API)

Lyrics can now be **time-synced** (LRC) so a player can scroll and highlight the
current line, not just show a static block (S-300). The lyric lookup already
fetched synced lyrics from LRCLIB and stored them; this exposes them and adds the
embedded-first source chain you'd expect for your own library.

## Changed

- **`LyricsService` tries sources in order**, most trustworthy first:
  1. **Embedded** — a `.lrc` sidecar next to the track, then the file's own tags
     (id3 SYLT/USLT, Vorbis `LYRICS`), read with getID3. No network, and it's the
     user's own data.
  2. **Provider** — LRCLIB (free, no key), which returns both plain and synced.
  3. **Plain fallback** — when no timing exists anywhere, the words still show,
     just without the highlight.
- **New `lyricsPayloadFor()`** returns `['plain' => ?, 'synced' => ?]`; the old
  `lyricsFor()` still returns the plain words for callers that only need those.
- **`GET /api/v1/items/{id}/lyrics` now returns `{ lyrics, synced }`** — `synced`
  is LRC text (`[mm:ss.xx]` lines) or null when only unsynced words exist.
  `lyrics` is unchanged, so older clients keep working.

## Testing

- New `LyricsServiceTest` (3 pass): a `.lrc` sidecar is read as synced (with plain
  derived by stripping timestamps); the provider is used only when the file
  carries nothing; the API returns both fields.

## Next

The desktop/web player and the iOS Now Playing lyrics view render the synced
lyrics with a scroll-highlight (separate changes); this is the shared server
foundation both use.
