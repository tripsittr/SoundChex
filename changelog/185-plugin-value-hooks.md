# 185 — Three more values a plugin can change

**Merged** 2026-09-23 · **Issues** S-317

The filter machinery has existed since the plugin platform landed, but exactly
one value was ever passed through it — a track's title during enrichment. So a
plugin could customise almost nothing without rendering its own UI. Three more
points, chosen for leverage rather than count.

## What changed

| Filter | Applied to |
|---|---|
| `item.subtitle` | the line under a title, everywhere a row is drawn |
| `api.item` | the array every client decodes for an item |
| `search.results` | what a search answers, after de-duplication and the cap |

`api.item` is the widest: the web player, the desktop app and the native apps
all decode that array, so a key added there reaches every one of them at once.

`search.results` is applied **after** de-duplication and the 60-item cap, so a
plugin reorders or trims what actually ships rather than a longer list the cap
would then cut differently.

`item.subtitle` sits on the model rather than in a view, so a plugin that wants
"Artist · Album" instead of the artist alone changes it once.

## Worth knowing

- **Adding an API key is safe; removing or retyping one is not.** The native
  apps decode strictly, and a client expecting `title` to be a string does not
  survive it becoming null. The docs say so where the hook is documented.
- Every test asserts the **unfiltered** result too: a hook that changes a value
  when no plugin asked is a bug, not a feature.
- A filter that throws is logged and skipped, and the original value survives —
  one bad plugin cannot blank a field for everyone. That behaviour predates
  this change; there is now a test that says so.
- Tests: **922 of 928**, the same two pre-existing failures.

## Still wrong

- Notification text and player context are named in the issue and not done.
  Both want a caller that passes something worth filtering, and neither has one
  yet that a plugin could usefully change.
