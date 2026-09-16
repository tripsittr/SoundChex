# Offline Phase 3 — the real UI, offline

**Issue:** S-145 · **Status:** in progress · Follows OfflineSpotifyPlan.md Layer 1
(native downloads, device-verified 15 Sep).

## The goal

Offline must look and behave exactly like online. Today the offline shell is a
deliberate 60-row placeholder (`library/offline-shell.js`) for the app's own
planned-but-unbuilt "Phase 3" (`library/index.js:14-18`). This finishes it.

## What the assessment established

- The online UI is ~95% server-rendered Blade — but the client already has a
  rendering kernel: `library/render.js` reproduces Blade's exact classes,
  `library/query.js` reproduces the server's grouping/sort/paginate logic, and
  the `soundchex-library` IndexedDB mirror holds the catalogue (id, title, type,
  artist/album/duration, artwork URL, playable, …) for ~1,450 items in ~80 KB
  gzipped. So rendering the real UI offline is a continuation of the existing
  design, not a rewrite.
- Native downloads write to disk and are read back by `media_list` — verified on
  device. The play-source resolver (`player.preferLocalSource` →
  `storage.open` → `convertFileSrc`) is wired and correct.

## The two real gaps

1. **Artwork is URL-only, never cached** — offline every cover is a broken image
   that degrades to a type glyph. No blob cache exists. This is the biggest
   visual difference from online.
2. **The client renderer is a capped placeholder** — 60 rows, no home hero/rails,
   no filters, sparse detail, dead genres/playlists tabs.

## Plan — independently shippable pieces, each device-verified

### Piece 1 — Offline playback of downloaded tracks

`player.load()` sets the network `src` first and only then swaps in the local
file, so offline a downloaded track briefly errors on the dead network URL
before the local source lands. Reorder: when a local copy exists, resolve it
*before* touching the network. Also bind the player in the offline context —
`data-play` clicks are handled by `now-playing.js`, which the offline bundle
(`library/index.js`) does not load, so play buttons do nothing offline.

**Verify:** airplane mode, tap a downloaded song, it plays and scrubs.

### Piece 2 — Artwork blob cache

A new store (native: on-disk beside media, keyed by item; browser: IndexedDB)
holding cover bytes. Populated when an item is downloaded (and opportunistically
during sync for the visible set). `render.js` and the renderers resolve artwork
through it, falling back to the URL online and the glyph when truly absent.

**Verify:** airplane mode, downloaded songs show real covers.

### Piece 3 — Promote the client renderer to primary (the Phase 3 core)

Uncap the lists (`query.paginate` exists), add the home hero + rails renderer,
the full album/artist detail (track lists from the mirror), and the filter UI
(search/genre/owned/wishlist — `query.js` already has the logic). Make these the
path the app uses, not just an offline rescue, so online and offline are the
same code.

**Verify:** offline browse matches online screen-for-screen for music.

### Piece 4 — Extend the mirror

Add genres and playlists to the sync payload so those tabs work offline.
Resume positions too, so a downloaded film reopens where it stopped.

**Verify:** genres/playlists populated offline; a film resumes.

## Out of scope (for now)

Captions and book page text (large, on-demand), and downloading the *entire*
catalogue's artwork (only downloaded items + the visible set are cached).

## Notes

- Each piece is a PR with tests and a changelog, device-verified before the next.
- Temporary debug instrumentation (disk logging, the gated TAURI badge) stays
  until Phase 3 is verified, then is removed in a cleanup PR.
