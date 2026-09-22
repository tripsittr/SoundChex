# 151 — Narrower review tables, so the cover and actions stay together

The Needs Review tables (Duplicates and Metadata) were too wide: with every
column shown, the cover and title on the left sat far from the merge/re-enrich
actions on the right, so reviewing meant scrolling back and forth for each row.
The same table renders in the browser and the desktop client, so both were
affected.

## Changed

- **Fewer columns by default.** The decision-critical ones stay; the rest are
  hidden by default and still toggleable from the columns menu:
  - **Duplicates tab** now shows Cover · Duplicate · Original · Kind · Reclaims
    (Type, Match, Status, Found, Hash toggle on).
  - **Metadata tab** now shows Cover · Item · Why · Confidence (Type, Status,
    Enriched toggle on).
- **Title columns are constrained** — wrapped and clamped to two lines rather
  than stretching the row with a long title or file path.
- **"Why" is now an info icon with the reason in its tooltip**, instead of a
  wide wrapped text column — the single biggest width saving on the Metadata tab.

The actions now sit next to the cover and title, so a row can be reviewed at a
glance. No behaviour change; the hidden columns are one click away.
