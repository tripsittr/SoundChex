# Episode lists and the film page, on every platform

The episode list was a file listing on both web and iOS: a number, a truncated
title, a chevron, with every season stacked at once. A sixty-episode series is
unusable that way, and an episode title alone ("Aunt Ginger") says nothing
about whether you have seen it.

Both now use the shape the streaming apps settled on, for the same reasons they
did: a **season picker**, and rows carrying a **still**, a **duration** and a
**sentence about what happens**. The still is the strongest cue of the four —
recognising a frame is faster than reading a synopsis. Part-watched episodes
carry a resume bar.

The web picker is radio inputs and sibling CSS, so it needs no JavaScript and
keeps working in the desktop shell.

## Continue Watching cards

A card read "But at Last Came a Knock" with nothing saying it was Shameless.
Cards now show the **series** as the headline and `S1:E9 <episode>` beneath it,
with a resume bar along the bottom. Web and iOS both.

## The film page

Reshaped towards the reference: a full-width backdrop, left-aligned title, the
fact strip and capability badges (4K, Dolby Vision, 5.1, CC) under it, and the
synopsis directly beneath the play button.

It was a centred 220pt poster under a "FILM" eyebrow. The poster is the image
you just tapped, so repeating it small says nothing new; centred text stops the
eye at every line; and the eyebrow spent a line restating what the page
obviously is.

## Three things measured rather than assumed

**The N+1 guard was vacuous twice.** A generous fixed threshold passed with the
eager load removed, and a later one absorbed the per-card cost in its budget.
It now compares a 4-card shelf against a 20-card one, so a legitimate new eager
load raises both. With the `parent` eager load: 6 episodes cost 9 queries and 20
cost 8 — flat. Without: 14 and 27.

**The home page cost guard caught a real regression.** `resumePercent()` and the
poster's episode labels read `probe`, `parent` and `showMetadata` lazily, which
was a query per tile — `show_metadata` 3x and `media_probes` 4x as single-row
lookups. Both now answer "unknown" for a relation that was not eager-loaded,
matching the rule `lastPlayedAt` already followed. A separate test asserts the
shelf *does* load them, since the guard would otherwise let a card silently
render the old label.

**An unparseable episode lost its heading.** Hiding the season picker for a
single season also hid "Other", which is where an episode with no `S01E01` in
its filename lands — so it rendered under no heading at all. Caught by
`TelevisionViewTest`; the picker now shows for a single *named* season.

## Still to do

- `show_metadata` has **0 rows** on this machine and there are no `show`-type
  items, so all of this is exercised by fixtures rather than against a real
  series. Worth a look on a server with shows catalogued.
- Collection and More Like This tabs are not built.
- The resume frames from #513 still are not rendered by any client; episode
  stills fall back to whatever artwork the episode has.
