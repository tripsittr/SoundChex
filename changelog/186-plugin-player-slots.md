# 186 — Plugins can write into the player, not just the panel

**Merged** 2026-09-24 · **Issues** S-318

The last of the three server-side plugin UI tracks. S-316 gave plugins the
admin panel's chrome; this gives them the thing people actually listen with.

## What changed

The admin panel takes its positions from Filament. The media center has no such
system, so five slots are placed by hand in its Blade — each a deliberate
decision about where a plugin may write, rather than whatever markup happened
to be wrapped:

| Slot | Where | Context |
|---|---|---|
| `player.controls` | beside the transport in the now-playing bar | — |
| `player.meta` | under the title and artist in that bar | — |
| `album.detail` | below an album's track list | the album |
| `artist.detail` | below an artist's albums | the artist name |
| `song.row.actions` | at the end of a track row | the `MediaItem` |

`@pluginSlot('name', $context)` renders them. The directive resolves the
registry at render time rather than closing over it, so a plugin enabled during
this request is still seen.

Both bar slots are placed **last** in their containers, so a plugin's markup
cannot displace the transport or the duration.

## Worth knowing

- **`song.row.actions` runs per row.** A query there is a query per track on
  screen; the docs say so at the slot.
- A plugin that throws contributes nothing at that slot and is logged, and its
  neighbours in the same slot still render — one bad plugin does not blank a
  section for the others.
- Output is deliberately not escaped: a slot exists so a plugin can contribute
  markup, which is the same trust a plugin already has as code running on the
  server.
- Tests: **929 of 935**, the same two pre-existing failures. Seven new,
  including one that renders the real now-playing component and asserts both
  the plugin's markup and the bar's own controls survive.

## Still wrong

- The same Tailwind constraint applies here as everywhere else: a plugin's
  markup cannot use the app's utility classes, because the app's CSS was
  compiled before the plugin existed. Ship CSS with the plugin (S-350).
- Nothing yet for the native apps — that is the SDUI track (S-319/S-320), which
  needs a component protocol rather than HTML.
