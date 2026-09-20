# 116 — Integrations page: cards and a type filter

*2026-09-19.* · **Issue** S-260

## Done

The Integrations page listed every service as rows in stacked group lists — fine
at a handful, cramped as more providers arrive. It's now a **card grid** with a
**filter** by kind, ready for the integrations to come (Deezer, Discord/Slack,
notification hubs).

- Each integration is a **card** rather than a list row, so a connected one reads
  as its own object (a highlighted border and the Connected badge) instead of a
  line in a wall of text; the grid reflows from one column on a phone to three on
  a wide screen.
- **Filter chips** across the top — All plus each group (Acquisition, Film & TV,
  Music, Lyrics, Books, Artwork) — each showing its connected/total count, so the
  page says at a glance what is set up. Picking one shows just that group; the
  choice survives editing or unlinking a key.

The data, the connection status, and the set-up / manage / unlink flow are
unchanged — this is presentation. `visibleGroups()` applies the filter over the
existing `groupedRows()`, and `filterOptions()` builds the chips with counts.

## Worth knowing

- Foundation for the rest of the integrations work: Deezer as an artwork
  integration (S-259), Discord/Slack (S-262), notification hubs (S-263), and the
  plugin system (S-264) all land as new cards/groups here.
- No new group types yet — a "Communication" group appears once its first
  integration does.

## Tests

PHP: **678 passing** (+3). `IntegrationsPageTest` gains: the filter chips list the
groups, filtering to a group hides the others, and the default shows every group.
The existing 23 (render, status, set-up/manage/unlink, open control) unchanged.
Pint clean.
